<?php

namespace App\Domain\LeadProcessing\Contracts;

use App\Domain\LeadProcessing\Data\TransmissionResult;
use App\Models\Lead;

interface LeadTransmitter
{
    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult;
}
