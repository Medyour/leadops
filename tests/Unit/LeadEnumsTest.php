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
        $this->assertContains('processing.failed', array_column(ProcessingEventType::cases(), 'value'));
        $this->assertContains('review.requested', array_column(ProcessingEventType::cases(), 'value'));
    }
}
