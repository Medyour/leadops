<?php

namespace Database\Factories;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        $reference = (string) Str::uuid();
        $fictionalPhone = sprintf('+1202555%04d', fake()->numberBetween(100, 199));

        return [
            'source' => LeadSource::Form,
            'external_reference' => $reference,
            'first_name' => 'Prenom-'.fake()->numberBetween(1000, 9999),
            'last_name' => 'Nom-'.fake()->numberBetween(1000, 9999),
            'company' => 'Entreprise Fictive '.fake()->numberBetween(100, 999),
            'email' => "lead-{$reference}@example.test",
            'phone' => $fictionalPhone,
            'country' => 'FR',
            'service_interest' => ServiceInterest::Automation->value,
            'company_size' => 12,
            'message' => 'Message entièrement fictif destiné à la démonstration locale LeadOps.',
            'consent' => true,
            'received_at' => now(),
            'normalized_email' => "lead-{$reference}@example.test",
            'normalized_phone' => $fictionalPhone,
            'score' => null,
            'classification' => null,
            'assigned_to' => null,
            'status' => LeadStatus::Received,
            'duplicate_of' => null,
            'processing_attempts' => 0,
            'processed_at' => null,
        ];
    }

    public function valid(): static
    {
        return $this->state(fn (): array => [
            'status' => LeadStatus::Received,
        ]);
    }

    public function lowPriority(): static
    {
        return $this->state(fn (): array => [
            'company' => null,
            'country' => null,
            'company_size' => null,
            'service_interest' => ServiceInterest::Other->value,
            'score' => 35,
            'classification' => LeadClassification::Low,
            'status' => LeadStatus::Qualified,
        ]);
    }

    public function invalidEmail(): static
    {
        return $this->state(fn (): array => [
            'email' => 'adresse-invalide',
            'normalized_email' => null,
            'phone' => null,
            'normalized_phone' => null,
            'status' => LeadStatus::Invalid,
        ]);
    }

    public function invalidPhone(): static
    {
        return $this->state(fn (): array => [
            'email' => null,
            'normalized_email' => null,
            'phone' => 'numero-invalide',
            'normalized_phone' => null,
            'status' => LeadStatus::Invalid,
        ]);
    }

    public function duplicateOf(Lead $lead): static
    {
        return $this->state(fn (): array => [
            'email' => $lead->email,
            'phone' => $lead->phone,
            'normalized_email' => $lead->normalized_email,
            'normalized_phone' => $lead->normalized_phone,
            'status' => LeadStatus::Duplicate,
            'duplicate_of' => $lead->getKey(),
        ]);
    }

    public function needsReview(): static
    {
        return $this->state(fn (): array => [
            'service_interest' => 'service_inconnu',
            'status' => LeadStatus::NeedsReview,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => LeadStatus::Failed,
            'processing_attempts' => 3,
        ]);
    }

    public function routed(): static
    {
        return $this->state(fn (): array => [
            'score' => 85,
            'classification' => LeadClassification::Hot,
            'assigned_to' => 'Sarah',
            'status' => LeadStatus::Routed,
            'processing_attempts' => 1,
            'processed_at' => now(),
        ]);
    }
}
