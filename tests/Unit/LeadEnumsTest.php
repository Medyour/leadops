<?php

namespace Tests\Unit;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use PHPUnit\Framework\TestCase;

class LeadEnumsTest extends TestCase
{
    public function test_domain_enums_expose_the_documented_stable_values(): void
    {
        $this->assertSame(
            ['RECEIVED', 'VALIDATING', 'INVALID', 'DUPLICATE', 'QUALIFIED', 'NEEDS_REVIEW', 'ROUTED', 'FAILED'],
            array_column(LeadStatus::cases(), 'value'),
        );
        $this->assertSame(['HOT', 'WARM', 'LOW'], array_column(LeadClassification::cases(), 'value'));
        $this->assertSame(['form', 'csv', 'webhook'], array_column(LeadSource::cases(), 'value'));
        $this->assertSame(
            ['automation', 'api', 'business_application', 'dashboard', 'other'],
            array_column(ServiceInterest::cases(), 'value'),
        );
        $this->assertSame([
            'lead.received',
            'processing.started',
            'validation.failed',
            'validation.succeeded',
            'lead.normalized',
            'duplicate.checked',
            'duplicate.detected',
            'score.calculated',
            'lead.classified',
            'lead.routing_decided',
            'lead.qualified',
            'lead.routed',
            'transmission.succeeded',
            'transmission.failed',
            'processing.attempt_failed',
            'processing.retry_scheduled',
            'processing.failed',
            'review.requested',
        ], array_column(ProcessingEventType::cases(), 'value'));
    }

    public function test_status_transitions_match_the_documented_matrix(): void
    {
        $expected = [
            'RECEIVED' => ['VALIDATING'],
            'VALIDATING' => ['INVALID', 'DUPLICATE', 'QUALIFIED', 'NEEDS_REVIEW', 'FAILED'],
            'INVALID' => [],
            'DUPLICATE' => [],
            'QUALIFIED' => ['ROUTED', 'NEEDS_REVIEW', 'FAILED'],
            'NEEDS_REVIEW' => ['VALIDATING'],
            'ROUTED' => [],
            'FAILED' => ['VALIDATING'],
        ];

        foreach (LeadStatus::cases() as $from) {
            $this->assertSame(
                $expected[$from->value],
                array_column($from->allowedTransitions(), 'value'),
                "Unexpected transitions from {$from->value}.",
            );

            foreach (LeadStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $expected[$from->value], true),
                    $from->canTransitionTo($to),
                    "Unexpected transition from {$from->value} to {$to->value}.",
                );
            }
        }
    }
}
