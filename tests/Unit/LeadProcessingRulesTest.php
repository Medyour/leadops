<?php

namespace Tests\Unit;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\LeadBusinessValidator;
use App\Domain\LeadProcessing\LeadClassifier;
use App\Domain\LeadProcessing\LeadNormalizer;
use App\Domain\LeadProcessing\LeadRouter;
use App\Domain\LeadProcessing\LeadScorer;
use PHPUnit\Framework\TestCase;

class LeadProcessingRulesTest extends TestCase
{
    public function test_normalization_cleans_each_documented_field_and_is_idempotent(): void
    {
        $normalizer = new LeadNormalizer(['FR' => '33']);
        $input = [
            'source' => ' form ',
            'external_reference' => ' ref-001 ',
            'first_name' => "  Alice\t  Fictive ",
            'last_name' => '  Exemple   Test ',
            'company' => '  Atelier   Imaginaire ',
            'email' => '  ALICE@EXAMPLE.TEST ',
            'phone' => ' 06 12 34 56 78 ',
            'country' => ' fr ',
            'service_interest' => ' automation ',
            'company_size' => 8,
            'message' => "  Besoin   fictif\n pour   une automatisation. ",
            'consent' => true,
        ];

        $normalized = $normalizer->normalize($input);

        $this->assertSame('form', $normalized['source']);
        $this->assertSame('ref-001', $normalized['external_reference']);
        $this->assertSame('Alice Fictive', $normalized['first_name']);
        $this->assertSame('Exemple Test', $normalized['last_name']);
        $this->assertSame('Atelier Imaginaire', $normalized['company']);
        $this->assertSame('alice@example.test', $normalized['email']);
        $this->assertSame('alice@example.test', $normalized['normalized_email']);
        $this->assertSame('+33612345678', $normalized['phone']);
        $this->assertSame('+33612345678', $normalized['normalized_phone']);
        $this->assertSame('FR', $normalized['country']);
        $this->assertSame('Besoin fictif pour une automatisation.', $normalized['message']);
        $this->assertSame($normalized, $normalizer->normalize($normalized));
    }

    public function test_normalization_converts_optional_empty_values_to_null_and_preserves_invalid_contact_for_review(): void
    {
        $normalized = (new LeadNormalizer([]))->normalize([
            'source' => 'form',
            'external_reference' => 'ref-002',
            'first_name' => 'Prenom-Fictif',
            'last_name' => 'Nom-Fictif',
            'company' => '   ',
            'email' => ' ADRESSE-INVALIDE ',
            'phone' => ' numéro-invalide ',
            'country' => ' ',
            'service_interest' => 'other',
        ]);

        $this->assertNull($normalized['company']);
        $this->assertNull($normalized['country']);
        $this->assertNull($normalized['message']);
        $this->assertSame('adresse-invalide', $normalized['email']);
        $this->assertNull($normalized['normalized_email']);
        $this->assertSame('numéro-invalide', $normalized['phone']);
        $this->assertNull($normalized['normalized_phone']);
    }

    public function test_business_validation_distinguishes_invalid_data_from_an_unknown_service_review(): void
    {
        $validator = new LeadBusinessValidator(['automation', 'other']);
        $valid = $this->baseAttributes();

        $this->assertFalse($validator->validate($valid)->isInvalid());
        $this->assertFalse($validator->validate($valid)->needsReview());

        $review = $validator->validate([...$valid, 'service_interest' => 'custom_service']);
        $this->assertFalse($review->isInvalid());
        $this->assertTrue($review->needsReview());
        $this->assertSame(['service_interest.unknown'], $review->reviewReasonCodes);

        $invalid = $validator->validate([
            ...$valid,
            'source' => 'unknown',
            'first_name' => '',
            'consent' => false,
            'normalized_email' => null,
            'normalized_phone' => null,
            'company_size' => 0,
        ]);
        $this->assertTrue($invalid->isInvalid());
        $this->assertContains('source.invalid', $invalid->invalidReasonCodes);
        $this->assertContains('first_name.required', $invalid->invalidReasonCodes);
        $this->assertContains('consent.required', $invalid->invalidReasonCodes);
        $this->assertContains('contact.invalid', $invalid->invalidReasonCodes);
        $this->assertContains('company_size.invalid', $invalid->invalidReasonCodes);
    }

    public function test_each_scoring_rule_applies_its_configured_weight(): void
    {
        $scorer = new LeadScorer($this->scoreConfig(), ['automation', 'api', 'business_application', 'dashboard', 'other']);
        $base = $this->scoreAttributes();

        $this->assertSame(20, $scorer->score($base)->score);
        $this->assertSame(30, $scorer->score([...$base, 'company_size' => 1])->score);
        $this->assertSame(40, $scorer->score([...$base, 'company_size' => 5])->score);
        $this->assertSame(30, $scorer->score([...$base, 'normalized_email' => 'lead@example.test'])->score);
        $this->assertSame(30, $scorer->score([...$base, 'normalized_phone' => '+12025550100'])->score);
        $this->assertSame(40, $scorer->score([...$base, 'service_interest' => 'automation'])->score);
        $this->assertSame(30, $scorer->score([...$base, 'message' => str_repeat('a', 80)])->score);
        $this->assertSame(25, $scorer->score([...$base, 'normalized_email' => 'lead@gmail.com'])->score);
        $this->assertSame(10, $scorer->score([...$base, 'company' => null])->score);
        $this->assertSame(10, $scorer->score([...$base, 'country' => null])->score);

        $complete = $scorer->score([
            ...$base,
            'company_size' => 5,
            'normalized_email' => 'lead@gmail.com',
            'normalized_phone' => '+12025550100',
            'service_interest' => 'automation',
            'message' => str_repeat('a', 80),
        ]);
        $this->assertSame(85, $complete->score);
        $this->assertSame([
            'base:+20',
            'company_size_present:+10',
            'company_size_at_least_five:+10',
            'valid_email:+10',
            'valid_phone:+10',
            'recognized_service:+20',
            'long_message:+10',
            'generic_email_domain:-5',
        ], $complete->ruleCodes);
    }

    public function test_score_is_bounded_at_zero_and_one_hundred(): void
    {
        $minimumConfig = $this->scoreConfig();
        $minimumConfig['base'] = -100;
        $maximumConfig = $this->scoreConfig();
        $maximumConfig['base'] = 200;

        $this->assertSame(0, (new LeadScorer($minimumConfig, []))->score($this->scoreAttributes())->score);
        $this->assertSame(100, (new LeadScorer($maximumConfig, []))->score($this->scoreAttributes())->score);
    }

    public function test_classification_uses_every_documented_boundary(): void
    {
        $classifier = new LeadClassifier(['HOT' => 80, 'WARM' => 50, 'LOW' => 0]);

        $this->assertSame(LeadClassification::Low, $classifier->classify(0));
        $this->assertSame(LeadClassification::Low, $classifier->classify(49));
        $this->assertSame(LeadClassification::Warm, $classifier->classify(50));
        $this->assertSame(LeadClassification::Warm, $classifier->classify(79));
        $this->assertSame(LeadClassification::Hot, $classifier->classify(80));
        $this->assertSame(LeadClassification::Hot, $classifier->classify(100));
    }

    public function test_routing_covers_every_service_and_unknown_fallback(): void
    {
        $router = new LeadRouter([
            'automation' => 'Sarah',
            'api' => 'Sarah',
            'business_application' => 'Yassine',
            'dashboard' => 'Yassine',
            'other' => 'Emma',
        ]);

        $this->assertSame('Sarah', $router->route('automation')->assignedTo);
        $this->assertSame('Sarah', $router->route('api')->assignedTo);
        $this->assertSame('Yassine', $router->route('business_application')->assignedTo);
        $this->assertSame('Yassine', $router->route('dashboard')->assignedTo);
        $this->assertSame('Emma', $router->route('other')->assignedTo);
        $this->assertTrue($router->route('unknown')->needsReview());
    }

    /** @return array<string, mixed> */
    private function baseAttributes(): array
    {
        return [
            'source' => 'form',
            'external_reference' => 'ref-001',
            'first_name' => 'Prenom-Fictif',
            'last_name' => 'Nom-Fictif',
            'service_interest' => 'automation',
            'consent' => true,
            'normalized_email' => 'lead@example.test',
            'normalized_phone' => null,
            'company_size' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function scoreAttributes(): array
    {
        return [
            'company_size' => null,
            'normalized_email' => null,
            'normalized_phone' => null,
            'service_interest' => 'unknown',
            'message' => null,
            'company' => 'Entreprise Fictive',
            'country' => 'FR',
        ];
    }

    /** @return array<string, mixed> */
    private function scoreConfig(): array
    {
        return [
            'minimum' => 0,
            'maximum' => 100,
            'base' => 20,
            'weights' => [
                'company_size_present' => 10,
                'company_size_at_least_five' => 10,
                'valid_email' => 10,
                'valid_phone' => 10,
                'recognized_service' => 20,
                'long_message' => 10,
                'generic_email_domain' => -5,
                'missing_company_or_country' => -10,
            ],
            'long_message_threshold' => 80,
            'generic_email_domains' => ['gmail.com'],
        ];
    }
}
