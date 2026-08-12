<?php

namespace Database\Seeders;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use App\Models\Lead;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadDemoSeeder extends Seeder
{
    private const RECEIVED_AT = '2026-01-15 09:00:00';

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->upsertLead('demo-hot-001', [
                'first_name' => 'Aline-Fictive',
                'company' => 'Atelier Fictif',
                'email' => 'aline-fictive@example.test',
                'phone' => '+12025550100',
                'normalized_email' => 'aline-fictive@example.test',
                'normalized_phone' => '+12025550100',
                'score' => 85,
                'classification' => LeadClassification::Hot->value,
                'assigned_to' => 'Sarah',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:05:00',
            ]);

            $this->upsertLead('demo-warm-001', [
                'first_name' => 'Benoit-Fictif',
                'company' => 'Bureau Imaginaire',
                'email' => 'benoit-fictif@example.test',
                'phone' => '+12025550101',
                'normalized_email' => 'benoit-fictif@example.test',
                'normalized_phone' => '+12025550101',
                'service_interest' => ServiceInterest::Dashboard->value,
                'score' => 65,
                'classification' => LeadClassification::Warm->value,
                'assigned_to' => 'Yassine',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:06:00',
            ]);

            $this->upsertLead('demo-low-001', [
                'first_name' => 'Camille-Fictive',
                'company' => null,
                'email' => 'camille-fictive@example.test',
                'phone' => '+12025550102',
                'country' => null,
                'service_interest' => ServiceInterest::Other->value,
                'company_size' => null,
                'normalized_email' => 'camille-fictive@example.test',
                'normalized_phone' => '+12025550102',
                'score' => 35,
                'classification' => LeadClassification::Low->value,
                'assigned_to' => 'Emma',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:07:00',
            ]);

            $this->upsertLead('demo-invalid-001', [
                'first_name' => 'Dorian-Fictif',
                'company' => 'Structure Fictive',
                'email' => 'adresse-invalide',
                'phone' => null,
                'normalized_email' => null,
                'normalized_phone' => null,
                'status' => LeadStatus::Invalid->value,
            ]);

            $hot = $this->findLead('demo-hot-001');
            $warm = $this->findLead('demo-warm-001');

            $this->upsertLead('demo-duplicate-001', [
                'first_name' => 'Elise-Fictive',
                'company' => 'Doublon Fictif',
                'email' => $hot->email,
                'phone' => $hot->phone,
                'normalized_email' => $hot->normalized_email,
                'normalized_phone' => $hot->normalized_phone,
                'status' => LeadStatus::Duplicate->value,
                'duplicate_of' => $hot->getKey(),
            ]);

            $this->upsertLead('demo-review-001', [
                'first_name' => 'Farid-Fictif',
                'company' => 'Revue Fictive',
                'email' => $hot->email,
                'phone' => $warm->phone,
                'normalized_email' => $hot->normalized_email,
                'normalized_phone' => $warm->normalized_phone,
                'service_interest' => ServiceInterest::Api->value,
                'status' => LeadStatus::NeedsReview->value,
            ]);

            $this->upsertLead('demo-failed-001', [
                'first_name' => 'Gaelle-Fictive',
                'company' => 'Incident Fictif',
                'email' => 'gaelle-fictive@example.test',
                'phone' => '+12025550106',
                'normalized_email' => 'gaelle-fictive@example.test',
                'normalized_phone' => '+12025550106',
                'status' => LeadStatus::Failed->value,
                'processing_attempts' => 3,
            ]);

            $this->upsertHotLeadTimeline($hot);
        });
    }

    /** @param array<string, mixed> $overrides */
    private function upsertLead(string $externalReference, array $overrides): void
    {
        DB::table('leads')->updateOrInsert(
            [
                'source' => LeadSource::Form->value,
                'external_reference' => $externalReference,
            ],
            array_merge([
                'first_name' => 'Prenom-Fictif',
                'last_name' => 'Demo',
                'company' => 'Entreprise Fictive',
                'email' => "{$externalReference}@example.test",
                'phone' => '+12025550199',
                'country' => 'FR',
                'service_interest' => ServiceInterest::Automation->value,
                'company_size' => 12,
                'message' => 'Message entièrement fictif destiné à la démonstration locale LeadOps.',
                'consent' => true,
                'received_at' => self::RECEIVED_AT,
                'normalized_email' => "{$externalReference}@example.test",
                'normalized_phone' => '+12025550199',
                'score' => null,
                'classification' => null,
                'assigned_to' => null,
                'status' => LeadStatus::Received->value,
                'duplicate_of' => null,
                'processing_attempts' => 0,
                'processed_at' => null,
                'created_at' => self::RECEIVED_AT,
                'updated_at' => self::RECEIVED_AT,
            ], $overrides),
        );
    }

    private function findLead(string $externalReference): Lead
    {
        return Lead::query()
            ->where('source', LeadSource::Form->value)
            ->where('external_reference', $externalReference)
            ->sole();
    }

    private function upsertHotLeadTimeline(Lead $lead): void
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
            DB::table('processing_events')->updateOrInsert(
                ['lead_id' => $lead->getKey(), 'type' => $type->value],
                [
                    'from_status' => $fromStatus?->value,
                    'to_status' => $toStatus->value,
                    'attempt' => 1,
                    'context' => json_encode(
                        ['scenario' => 'demo', 'step' => $position + 1],
                        JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => sprintf('2026-01-15 09:00:%02d', $position),
                ],
            );
        }
    }
}
