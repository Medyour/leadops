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
use App\Models\ProcessingEvent;
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

    public function process(
        int $leadId,
        ?string $cycleId = null,
        ?int $queueAttempt = null,
    ): void {
        $preparation = DB::transaction(function () use ($leadId, $cycleId, $queueAttempt): array|Throwable|null {
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);

            if (! in_array($lead->status, [LeadStatus::Received, LeadStatus::Validating, LeadStatus::Qualified], true)) {
                return null;
            }

            if (! $this->isCurrentCycle($lead, $cycleId)) {
                return null;
            }

            $maximumAttempts = (int) config('leadops.processing.max_attempts');
            if ($lead->processing_attempts >= $maximumAttempts) {
                if (in_array($lead->status, [LeadStatus::Validating, LeadStatus::Qualified], true)) {
                    $this->transition->transition(
                        $lead,
                        LeadStatus::Failed,
                        $lead->processing_attempts,
                        $this->cycleContext($cycleId, ['error_code' => 'attempts_exhausted']),
                    );
                }

                return null;
            }

            $attempt = $queueAttempt ?? $lead->processing_attempts + 1;

            if ($attempt < 1
                || $attempt > $maximumAttempts
                || ($queueAttempt !== null && $lead->processing_attempts + 1 !== $attempt)) {
                return null;
            }

            $lead->forceFill(['processing_attempts' => $attempt])->save();

            if ($lead->status === LeadStatus::Received) {
                $this->transition->transition(
                    $lead,
                    LeadStatus::Validating,
                    $attempt,
                    $cycleId === null ? [] : ['cycle_id' => $cycleId],
                );
            } else {
                $this->recorder->record(
                    $lead,
                    ProcessingEventType::ProcessingStarted,
                    $attempt,
                    $cycleId === null ? [] : ['cycle_id' => $cycleId],
                    fromStatus: $lead->status,
                );
            }

            try {
                if ($lead->status === LeadStatus::Validating) {
                    if (! $this->qualify($lead, $attempt, $cycleId)) {
                        return null;
                    }
                }
            } catch (Throwable $exception) {
                $this->recordFailureEvents(
                    $lead,
                    $attempt,
                    ProcessingEventType::ProcessingAttemptFailed,
                    'technical_failure',
                    $cycleId,
                );

                return $exception;
            }

            return [
                'attempt' => $attempt,
                'idempotency_key' => "lead:{$lead->getKey()}:cycle:".($cycleId ?? 'initial').':transmission',
                'lead' => $lead,
            ];
        });

        if ($preparation instanceof Throwable) {
            throw $preparation;
        }

        if ($preparation === null) {
            return;
        }

        /** @var Lead $lead */
        $lead = $preparation['lead'];
        $attempt = $preparation['attempt'];
        $idempotencyKey = $preparation['idempotency_key'];

        try {
            $result = $this->transmitter->transmit($lead, $idempotencyKey);

            if (! $result->successful) {
                throw new LeadTransmissionException($result->errorCode ?? 'transmission_unavailable');
            }
        } catch (Throwable $exception) {
            $this->recordTechnicalFailure(
                $leadId,
                $attempt,
                $cycleId,
                ProcessingEventType::TransmissionFailed,
                $exception instanceof LeadTransmissionException
                    ? 'transmission_failed'
                    : 'technical_failure',
            );

            throw $exception;
        }

        try {
            DB::transaction(function () use ($leadId, $attempt, $cycleId, $idempotencyKey, $result): void {
                $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);

                if ($lead->status === LeadStatus::Routed
                    || $lead->status !== LeadStatus::Qualified
                    || $lead->processing_attempts !== $attempt
                    || ! $this->isCurrentCycle($lead, $cycleId)) {
                    return;
                }

                $this->recorder->record(
                    $lead,
                    ProcessingEventType::TransmissionSucceeded,
                    $attempt,
                    $this->cycleContext($cycleId, [
                        'reference_fingerprint' => substr(hash('sha256', $result->reference ?? 'simulated'), 0, 16),
                        'idempotency_key' => $idempotencyKey,
                    ]),
                    $lead->status,
                );
                $this->transition->transition(
                    $lead,
                    LeadStatus::Routed,
                    $attempt,
                    $this->cycleContext($cycleId, ['assigned_to' => $lead->assigned_to]),
                );
            });
        } catch (Throwable $exception) {
            $this->recordTechnicalFailure(
                $leadId,
                $attempt,
                $cycleId,
                ProcessingEventType::ProcessingAttemptFailed,
                'technical_failure',
            );

            throw $exception;
        }
    }

    public function markFailedAfterExhaustion(
        int $leadId,
        ?int $queueAttempts = null,
        ?string $cycleId = null,
    ): void {
        DB::transaction(function () use ($leadId, $queueAttempts, $cycleId): void {
            $lead = Lead::query()->lockForUpdate()->find($leadId);
            $maximumAttempts = (int) config('leadops.processing.max_attempts');

            if ($lead === null
                || ! in_array($lead->status, [LeadStatus::Validating, LeadStatus::Qualified], true)
                || ! $this->isCurrentCycle($lead, $cycleId)) {
                return;
            }

            $attempts = max($lead->processing_attempts, min($queueAttempts ?? 0, $maximumAttempts));

            if ($attempts < $maximumAttempts) {
                return;
            }

            if ($attempts !== $lead->processing_attempts) {
                $lead->forceFill(['processing_attempts' => $attempts])->save();
            }

            $this->transition->transition(
                $lead,
                LeadStatus::Failed,
                $attempts,
                $this->cycleContext($cycleId, ['error_code' => 'attempts_exhausted']),
            );
        });
    }

    private function recordTechnicalFailure(
        int $leadId,
        int $attempt,
        ?string $cycleId,
        ProcessingEventType $eventType,
        string $errorCode,
    ): void {
        DB::transaction(function () use ($leadId, $attempt, $cycleId, $eventType, $errorCode): void {
            $lead = Lead::query()->lockForUpdate()->find($leadId);

            if ($lead === null
                || ! in_array($lead->status, [LeadStatus::Validating, LeadStatus::Qualified], true)
                || $lead->processing_attempts !== $attempt
                || ! $this->isCurrentCycle($lead, $cycleId)) {
                return;
            }

            $this->recordFailureEvents($lead, $attempt, $eventType, $errorCode, $cycleId);
        });
    }

    private function recordFailureEvents(
        Lead $lead,
        int $attempt,
        ProcessingEventType $eventType,
        string $errorCode,
        ?string $cycleId = null,
    ): void {
        $this->recorder->record(
            $lead,
            $eventType,
            $attempt,
            $this->cycleContext($cycleId, ['error_code' => $errorCode]),
            $lead->status,
        );

        if ($attempt < (int) config('leadops.processing.max_attempts')) {
            $backoff = config('leadops.processing.backoff', []);
            $retryDelay = (int) ($backoff[$attempt - 1] ?? 0);
            $this->recorder->record(
                $lead,
                ProcessingEventType::ProcessingRetryScheduled,
                $attempt,
                $this->cycleContext($cycleId, ['error_code' => $errorCode, 'retry_delay' => $retryDelay]),
                $lead->status,
            );
        }
    }

    private function isCurrentCycle(Lead $lead, ?string $cycleId): bool
    {
        $latestAction = ProcessingEvent::query()
            ->where('lead_id', $lead->getKey())
            ->whereIn('type', [
                ProcessingEventType::ReviewReprocessingRequested->value,
                ProcessingEventType::ManualRetryRequested->value,
            ])
            ->latest('id')
            ->first();

        if ($latestAction === null) {
            return $cycleId === null;
        }

        $currentCycleId = $latestAction->context['cycle_id'] ?? null;

        return is_string($cycleId)
            && is_string($currentCycleId)
            && hash_equals($currentCycleId, $cycleId);
    }

    private function qualify(Lead $lead, int $attempt, ?string $cycleId): bool
    {
        $normalized = $this->normalizer->normalize($lead->getAttributes());
        $lead->forceFill($normalized)->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadNormalized,
            $attempt,
            $this->cycleContext($cycleId),
            fromStatus: $lead->status,
        );

        $validation = $this->validator->validate($normalized);
        if ($validation->isInvalid()) {
            $this->transition->transition(
                $lead,
                LeadStatus::Invalid,
                $attempt,
                $this->cycleContext($cycleId, ['reason_codes' => $validation->invalidReasonCodes]),
            );

            return false;
        }

        if ($validation->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                $this->cycleContext($cycleId, ['reason_codes' => $validation->reviewReasonCodes]),
            );

            return false;
        }

        $this->recorder->record(
            $lead,
            ProcessingEventType::ValidationSucceeded,
            $attempt,
            $this->cycleContext($cycleId),
            fromStatus: $lead->status,
        );

        $duplicates = $this->duplicateDetector->detect($lead);
        if ($duplicates->isDuplicate()) {
            $lead->forceFill(['duplicate_of' => $duplicates->duplicateOf])->save();
            $this->transition->transition(
                $lead,
                LeadStatus::Duplicate,
                $attempt,
                $this->cycleContext($cycleId, ['candidate_ids' => $duplicates->candidateIds]),
            );

            return false;
        }

        if ($duplicates->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                $this->cycleContext($cycleId, [
                    'reason_codes' => ['duplicate.ambiguous'],
                    'candidate_ids' => $duplicates->candidateIds,
                ]),
            );

            return false;
        }

        $this->recorder->record(
            $lead,
            ProcessingEventType::DuplicateChecked,
            $attempt,
            $this->cycleContext($cycleId),
            fromStatus: $lead->status,
        );

        $score = $this->scorer->score($normalized);
        $lead->forceFill(['score' => $score->score])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::ScoreCalculated,
            $attempt,
            $this->cycleContext($cycleId, ['score' => $score->score, 'rule_codes' => $score->ruleCodes]),
            $lead->status,
        );

        $classification = $this->classifier->classify($score->score);
        $lead->forceFill(['classification' => $classification])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadClassified,
            $attempt,
            $this->cycleContext($cycleId, ['classification' => $classification->value]),
            $lead->status,
        );

        $routing = $this->router->route($lead->service_interest);
        if ($routing->needsReview()) {
            $this->transition->transition(
                $lead,
                LeadStatus::NeedsReview,
                $attempt,
                $this->cycleContext($cycleId, ['reason_codes' => ['routing.unknown_service']]),
            );

            return false;
        }

        $lead->forceFill(['assigned_to' => $routing->assignedTo])->save();
        $this->recorder->record(
            $lead,
            ProcessingEventType::LeadRoutingDecided,
            $attempt,
            $this->cycleContext($cycleId, ['service' => $lead->service_interest, 'assigned_to' => $routing->assignedTo]),
            $lead->status,
        );
        $this->transition->transition(
            $lead,
            LeadStatus::Qualified,
            $attempt,
            $this->cycleContext($cycleId),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function cycleContext(?string $cycleId, array $context = []): array
    {
        return $cycleId === null
            ? $context
            : ['cycle_id' => $cycleId, ...$context];
    }
}
