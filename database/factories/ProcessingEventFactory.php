<?php

namespace Database\Factories;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProcessingEvent> */
class ProcessingEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'type' => ProcessingEventType::LeadReceived,
            'from_status' => null,
            'to_status' => LeadStatus::Received,
            'attempt' => null,
            'context' => [
                'source' => 'factory',
                'rule_codes' => [],
            ],
            'created_at' => now(),
        ];
    }
}
