<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Models\Lead;
use App\Services\Leads\ProcessingEventRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

final class LeadStatusTransition
{
    public function __construct(private ProcessingEventRecorder $recorder) {}

    /** @param array<string, mixed> $context */
    public function transition(Lead $lead, LeadStatus $to, ?int $attempt = null, array $context = []): void
    {
        DB::transaction(function () use ($lead, $to, $attempt, $context): void {
            $from = $lead->status;

            if (! $from->canTransitionTo($to)) {
                throw new DomainException("Transition from {$from->value} to {$to->value} is not allowed.");
            }

            $attributes = ['status' => $to];

            if ($to === LeadStatus::Validating && in_array($from, [LeadStatus::Failed, LeadStatus::NeedsReview], true)) {
                $attributes += [
                    'score' => null,
                    'classification' => null,
                    'assigned_to' => null,
                    'duplicate_of' => null,
                    'processing_attempts' => 0,
                    'processed_at' => null,
                ];
            }

            if ($to === LeadStatus::Routed) {
                $attributes['processed_at'] = now();
            }

            $lead->forceFill($attributes)->save();

            $this->recorder->record(
                $lead,
                $this->eventTypeFor($to),
                $attempt,
                $context,
                $from,
                $to,
            );
        });
    }

    private function eventTypeFor(LeadStatus $status): ProcessingEventType
    {
        return match ($status) {
            LeadStatus::Validating => ProcessingEventType::ProcessingStarted,
            LeadStatus::Invalid => ProcessingEventType::ValidationFailed,
            LeadStatus::Duplicate => ProcessingEventType::DuplicateDetected,
            LeadStatus::Qualified => ProcessingEventType::LeadQualified,
            LeadStatus::NeedsReview => ProcessingEventType::ReviewRequested,
            LeadStatus::Routed => ProcessingEventType::LeadRouted,
            LeadStatus::Failed => ProcessingEventType::ProcessingFailed,
            LeadStatus::Received => throw new DomainException('RECEIVED cannot be a transition target.'),
        };
    }
}
