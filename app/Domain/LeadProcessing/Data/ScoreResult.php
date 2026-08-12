<?php

namespace App\Domain\LeadProcessing\Data;

final readonly class ScoreResult
{
    /** @param list<string> $ruleCodes */
    public function __construct(
        public int $score,
        public array $ruleCodes,
    ) {}
}
