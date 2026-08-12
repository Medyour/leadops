<?php

namespace App\Services\Leads;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Domain\LeadProcessing\DuplicateDetector;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Exceptions\LeadTransmissionException;
use App\Domain\LeadProcessing\LeadBusinessValidator;
use App\Domain\LeadProcessing\LeadClassifier;
use App\Domain\LeadProcessing\LeadNormalizer;
use App\Domain\LeadProcessing\LeadRouter;
use App\Domain\LeadProcessing\LeadScorer;
use App\Domain\LeadProcessing\LeadStatusTransition;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LeadProcessor
{
    public function __construct(
        private LeadNormalizer $normalizer,
        private LeadBusinessValidator $validator,
        private DuplicateDetector $duplicateDetector,
        private LeadScorer $scorer,
        private LeadClassifier $classifier,
        private LeadRouter $router,
        private LeadTransmitter $transmitter,
        private LeadStatusTransition $transition,
        private ProcessingEventRecorder $recorder,
    ) {}

    public function process(int $leadId): void
    {
        $failure = DB::transaction(function () use ($leadId): ?Throwable {
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);

            if (! in_array($lead->status, [LeadStatus::Received, LeadStatus::Validating, LeadStatus::Qualified], true)) {
                return null;
            }

            $maximumAttempts = (int) config('leadops.processing.max_attempts');
            if ($lead->processing_attempts >= $maximumAttempts) {
                return null;
            }

            $attempt = $lead->processing_attempts + 1;
            $lead->forceFill(['processing_attempts' => $attempt])->save();

            if ($lead->status === LeadStatus::Received) {
                $this->transition->transition($lead, LeadStatus::Validating, $attempt);
            } else {
                $this->recorder->record(
                    $lead,
                    ProcessingEventType::ProcessingStarted,
                    $attempt,
                    fromStatus: $lead->status,
                );
            }

            $stage = 'qualification';

            try {
                if ($lead->status === LeadStatus::Validating) {
                    if (! $this->qualify($lead, $attempt)) {
                        return null;
                    }
                }

                $stage = 'transmission';
                $idempotencyKey = "lead:{$lead->getKey()}:transmission";
                $result = $this->transmitter->transmit($lead, $idempotencyKey);

                if (! $result->successful) {
                    throw new LeadTransmissionException($result->errorCode ?? 'transmission_unavailable');
                }

                $this->recorder->record(
                    $lead,
                    ProcessingEventType::TransmissionSucceeded,
                    $attempt,
                    [
                        'reference' => $result->reference ?? 'simulated',
                        'idempotency_key' => $idempotencyKey,
                    ],
                    $lead->status,
                );
                $this->transition->transition(
                    $lead,
                    LeadStatus::Routed,
                    $attempt,
                    ['assigned_to' => $lead->assigned_to],
                );

                return null;
            } catch (Throwable $exception) {
                $errorCode = $exception instanceof LeadTransmissionException
                    ? $exception->errorCode
                    : 'technical_failure';

                $this->recorder->record(
                    $lead,
                    $stage === 'transmission'
                        ? ProcessingEventType::TransmissionFailed
                        : ProcessingEventType::ProcessingAttemptFailed,
                    $attempt,
                    ['error_code' => $errorCode],
                    $lead->status,
                );

                if ($attempt < $maximumAttempts) {
                    $backoff = config('leadops.processing.backoff', []);
                    $retryDelay = (int) ($backoff[$attempt - 1] ?? 0);
                    $this->recorder->record(
                        $lead,
                        ProcessingEventType::ProcessingRetryScheduled,
                        $attempt,
                        ['error_code' => $errorCode, 'retry_delay' => $retryDelay],
                        $lead->status,
                    );
                }

                return $exception;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function markFailedAfterExhaustion(int $leadId): void
    {
        DB::transaction(function () use ($leadId): void {
            $lead = Lead::query()->lockForUpdate()->find($leadId);

            if ($lead === null
                || $lead->processing_attempts < (int) config('leadops.processing.max_attempts')
                || ! in_array($lead->status, [LeadStatus::Validating, LeadStatus::Qualified], true)) {
                return;
            }

            $this->transition->transition(
                $lead,
                LeadStatus::Failed,
                $lead->processing_attempts,
                ['error_code' => 'attempts_exhausted'],
            );
        });
    }

    private function qualify(Lead $lead, int $attempt): bool
    {
        $normalized = $this->normalizer->normalize($lead->getAttributes());
        $lead->forceFill($normalized)->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadNormalized,
            $attempt,
            fromStatus: $lead->status,
        );

        $validation = $this->validator->validate($normalized);
        if ($validation->isInvalid()) {
            $this->transition->transition(
                $lead,
                LeadStatus::Invalid,
                $attempt,
                ['reason_codes' => $validation->invalidReasonCodes],
            );

            return false;
        }

        if ($validation->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                ['reason_codes' => $validation->reviewReasonCodes],
            );

            return false;
        }

        $this->recorder->record(
            $lead,
            ProcessingEventType::ValidationSucceeded,
            $attempt,
            fromStatus: $lead->status,
        );

        $duplicates = $this->duplicateDetector->detect($lead);
        if ($duplicates->isDuplicate()) {
            $lead->forceFill(['duplicate_of' => $duplicates->duplicateOf])->save();
            $this->transition->transition(
                $lead,
                LeadStatus::Duplicate,
                $attempt,
                ['candidate_ids' => $duplicates->candidateIds],
            );

            return false;
        }

        if ($duplicates->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                [
                    'reason_codes' => ['duplicate.ambiguous'],
                    'candidate_ids' => $duplicates->candidateIds,
                ],
            );

            return false;
        }

        $this->recorder->record(
            $lead,
            ProcessingEventType::DuplicateChecked,
            $attempt,
            fromStatus: $lead->status,
        );

        $score = $this->scorer->score($normalized);
        $lead->forceFill(['score' => $score->score])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::ScoreCalculated,
            $attempt,
            ['score' => $score->score, 'rule_codes' => $score->ruleCodes],
            $lead->status,
        );

        $classification = $this->classifier->classify($score->score);
        $lead->forceFill(['classification' => $classification])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadClassified,
            $attempt,
            ['classification' => $classification->value],
            $lead->status,
        );

        $routing = $this->router->route($lead->service_interest);
        if ($routing->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                ['reason_codes' => ['routing.unknown_service']],
            );

            return false;
        }

        $lead->forceFill(['assigned_to' => $routing->assignedTo])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadRoutingDecided,
            $attempt,
            ['service' => $lead->service_interest, 'assigned_to' => $routing->assignedTo],
            $lead->status,
        );
        $this->transition->transition($lead, LeadStatus::Qualified, $attempt);

        return true;
    }
}
