<?php

namespace App\Domain\LeadProcessing\Data;

final readonly class ValidationResult
{
    /**
     * @param  list<string>  $invalidReasonCodes
     * @param  list<string>  $reviewReasonCodes
     */
    public function __construct(
        public array $invalidReasonCodes = [],
        public array $reviewReasonCodes = [],
    ) {}

    public function isInvalid(): bool
    {
        return $this->invalidReasonCodes !== [];
    }

    public function needsReview(): bool
    {
        return ! $this->isInvalid() && $this->reviewReasonCodes !== [];
    }
}
