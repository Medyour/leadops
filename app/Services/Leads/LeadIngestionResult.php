<?php

namespace App\Services\Leads;

use App\Models\Lead;

final readonly class LeadIngestionResult
{
    public function __construct(
        public Lead $lead,
        public bool $created,
    ) {}
}
