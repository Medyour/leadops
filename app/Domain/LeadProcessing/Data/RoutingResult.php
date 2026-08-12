<?php

namespace App\Domain\LeadProcessing\Data;

final readonly class RoutingResult
{
    public function __construct(public ?string $assignedTo) {}

    public function needsReview(): bool
    {
        return $this->assignedTo === null;
    }
}
