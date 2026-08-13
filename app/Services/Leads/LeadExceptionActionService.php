<?php

namespace App\Services\Leads;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\LeadStatusTransition;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeadExceptionActionService
{
    /** @var list<string> */
    public const CORRECTABLE_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'email',
        'phone',
        'country',
        'service_interest',
        'company_size',
        'message',
        'consent',
    ];

    public function __construct(
        private LeadStatusTransition $transition,
        private ProcessingEventRecorder $recorder,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function correct(Lead $lead, array $attributes): bool
    {
        return DB::transaction(function () use ($lead, $attributes): bool {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $this->assertStatus($lockedLead, LeadStatus::NeedsReview);

            $allowed = array_intersect_key($attributes, array_flip(self::CORRECTABLE_FIELDS));
            $lockedLead->forceFill($allowed);
            $changedFields = array_values(array_intersect(
                self::CORRECTABLE_FIELDS,
                array_keys($lockedLead->getDirty()),
            ));

            if ($changedFields === []) {
                return false;
            }

            $lockedLead->save();
            $this->recorder->record(
                $lockedLead,
                ProcessingEventType::ReviewCorrected,
                context: ['changed_fields' => $changedFields],
                fromStatus: LeadStatus::NeedsReview,
            );

            return true;
        });
    }

    public function reprocess(Lead $lead): string
    {
        return $this->openCycle(
            $lead,
            LeadStatus::NeedsReview,
            ProcessingEventType::ReviewReprocessingRequested,
        );
    }

    public function retry(Lead $lead): string
    {
        return $this->openCycle(
            $lead,
            LeadStatus::Failed,
            ProcessingEventType::ManualRetryRequested,
        );
    }

    private function openCycle(
        Lead $lead,
        LeadStatus $expectedStatus,
        ProcessingEventType $eventType,
    ): string {
        return DB::transaction(function () use ($lead, $expectedStatus, $eventType): string {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $this->assertStatus($lockedLead, $expectedStatus);
            $cycleId = (string) Str::uuid();

            $this->transition->transition(
                $lockedLead,
                LeadStatus::Validating,
                context: ['cycle_id' => $cycleId],
                eventType: $eventType,
            );
            ProcessLead::dispatch($lockedLead->getKey(), $cycleId);

            return $cycleId;
        });
    }

    private function assertStatus(Lead $lead, LeadStatus $expected): void
    {
        if ($lead->status !== $expected) {
            throw new DomainException('This action is not available for the current lead status.');
        }
    }
}
