<?php

namespace App\Domain\LeadProcessing\Data;

final readonly class DuplicateCheckResult
{
    /** @param list<int> $candidateIds */
    public function __construct(
        public ?int $duplicateOf = null,
        public array $candidateIds = [],
    ) {}

    public function isDuplicate(): bool
    {
        return $this->duplicateOf !== null;
    }

    public function needsReview(): bool
    {
        return count($this->candidateIds) > 1;
    }
}
