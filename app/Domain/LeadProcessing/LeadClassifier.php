<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Enums\LeadClassification;

final class LeadClassifier
{
    /** @param array<string, int>|null $thresholds */
    public function __construct(private ?array $thresholds = null)
    {
        $this->thresholds ??= config('leadops.qualification.classification_thresholds');
    }

    public function classify(int $score): LeadClassification
    {
        return match (true) {
            $score >= $this->thresholds[LeadClassification::Hot->value] => LeadClassification::Hot,
            $score >= $this->thresholds[LeadClassification::Warm->value] => LeadClassification::Warm,
            default => LeadClassification::Low,
        };
    }
}
