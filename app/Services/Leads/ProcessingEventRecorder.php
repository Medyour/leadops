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
            'context' => $this->sanitize($context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_CONTEXT_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = array_slice(array_values(array_filter(
                    $value,
                    static fn (mixed $item): bool => is_int($item) || is_string($item),
                )), 0, 25);
            } elseif (is_int($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 120);
            }
        }

        return $safe;
    }
}
