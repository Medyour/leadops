<?php

namespace Database\Seeders;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use Illuminate\Database\Seeder;

class LeadDemoSeeder extends Seeder
{
    public function run(): void
    {
        $hot = Lead::factory()->routed()->create([
            'external_reference' => 'demo-hot-001',
            'first_name' => 'Aline-Fictive',
            'last_name' => 'Demo',
            'company' => 'Atelier Exemple',
        ]);

        Lead::factory()->routed()->create([
            'external_reference' => 'demo-warm-001',
            'first_name' => 'Benoit-Fictif',
            'last_name' => 'Demo',
            'company' => 'Bureau Imaginaire',
            'service_interest' => ServiceInterest::Dashboard->value,
            'score' => 65,
            'classification' => LeadClassification::Warm,
            'assigned_to' => 'Yassine',
        ]);

        Lead::factory()->lowPriority()->create([
            'external_reference' => 'demo-low-001',
            'first_name' => 'Camille-Fictive',
            'last_name' => 'Demo',
            'status' => LeadStatus::Routed,
            'assigned_to' => 'Emma',
            'processed_at' => now(),
        ]);

        Lead::factory()->invalidEmail()->create([
            'external_reference' => 'demo-invalid-001',
            'first_name' => 'Dorian-Fictif',
            'last_name' => 'Demo',
        ]);

        Lead::factory()->duplicateOf($hot)->create([
            'external_reference' => 'demo-duplicate-001',
            'first_name' => 'Elise-Fictive',
            'last_name' => 'Demo',
        ]);

        Lead::factory()->needsReview()->create([
            'external_reference' => 'demo-review-001',
            'first_name' => 'Farid-Fictif',
            'last_name' => 'Demo',
        ]);

        Lead::factory()->failed()->create([
            'external_reference' => 'demo-failed-001',
            'first_name' => 'Gaelle-Fictive',
            'last_name' => 'Demo',
        ]);

        $this->createHotLeadTimeline($hot);
    }

    private function createHotLeadTimeline(Lead $lead): void
    {
        $events = [
            [ProcessingEventType::LeadReceived, null, LeadStatus::Received],
            [ProcessingEventType::LeadNormalized, LeadStatus::Received, LeadStatus::Validating],
            [ProcessingEventType::ScoreCalculated, LeadStatus::Validating, LeadStatus::Qualified],
            [ProcessingEventType::LeadClassified, LeadStatus::Validating, LeadStatus::Qualified],
            [ProcessingEventType::TransmissionSucceeded, LeadStatus::Qualified, LeadStatus::Routed],
            [ProcessingEventType::LeadRouted, LeadStatus::Qualified, LeadStatus::Routed],
        ];

        foreach ($events as $position => [$type, $fromStatus, $toStatus]) {
            ProcessingEvent::factory()->for($lead)->create([
                'type' => $type,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'attempt' => 1,
                'context' => ['scenario' => 'demo', 'step' => $position + 1],
                'created_at' => now()->addSeconds($position),
            ]);
        }
    }
}
