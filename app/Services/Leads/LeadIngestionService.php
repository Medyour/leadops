<?php

namespace App\Services\Leads;

use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class LeadIngestionService
{
    public function __construct(private ProcessingEventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function ingest(
        LeadSource $source,
        string $externalReference,
        array $attributes,
    ): LeadIngestionResult {
        $receivedAttributes = Arr::only($attributes, [
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
            'received_at',
        ]);

        return DB::transaction(function () use ($source, $externalReference, $receivedAttributes): LeadIngestionResult {
            $lead = Lead::query()->createOrFirst(
                [
                    'source' => $source->value,
                    'external_reference' => $externalReference,
                ],
                [
                    ...$receivedAttributes,
                    'status' => LeadStatus::Received->value,
                    'processing_attempts' => 0,
                    'received_at' => $receivedAttributes['received_at'] ?? now(),
                ],
            );

            if (! $lead->wasRecentlyCreated) {
                return new LeadIngestionResult($lead, false);
            }

            $this->events->record(
                $lead,
                ProcessingEventType::LeadReceived,
                context: [
                    'reference_fingerprint' => substr(hash(
                        'sha256',
                        $source->value.'|'.$externalReference,
                    ), 0, 16),
                ],
                toStatus: LeadStatus::Received,
            );

            ProcessLead::dispatch($lead->getKey());

            return new LeadIngestionResult($lead, true);
        }, attempts: 3);
    }
}
