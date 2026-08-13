<?php

namespace App\Services\Leads;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Models\Lead;
use App\Models\ProcessingEvent;

final class ProcessingEventRecorder
{
    private const ALLOWED_CONTEXT_KEYS = [
        'assigned_to',
        'candidate_ids',
        'changed_fields',
        'classification',
        'cycle_id',
        'error_code',
        'idempotency_key',
        'reason_codes',
        'reference_fingerprint',
        'retry_delay',
        'rule_codes',
        'score',
        'service',
    ];

    /** @param array<string, mixed> $context */
    public function record(
        Lead $lead,
        ProcessingEventType $type,
        ?int $attempt = null,
        array $context = [],
        ?LeadStatus $fromStatus = null,
        ?LeadStatus $toStatus = null,
    ): ProcessingEvent {
        return $lead->processingEvents()->create([
            'type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'attempt' => $attempt,
            'context' => $this->sanitize($type, $context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitize(ProcessingEventType $type, array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_CONTEXT_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $items = array_slice(array_values(array_filter(
                    $value,
                    static fn (mixed $item): bool => is_int($item) || is_string($item),
                )), 0, 25);

                if ($key === 'changed_fields') {
                    $items = $type === ProcessingEventType::ReviewCorrected
                        ? array_values(array_intersect(LeadExceptionActionService::CORRECTABLE_FIELDS, $items))
                        : [];
                }

                $safe[$key] = $items;
            } elseif (is_int($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 120);
            }
        }

        return $safe;
    }
}
