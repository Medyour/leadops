<?php

namespace App\Domain\LeadProcessing\Contracts;

use App\Domain\LeadProcessing\Data\TransmissionResult;
use App\Models\Lead;

interface LeadTransmitter
{
    /**
     * Implementations must treat repeated calls with the same idempotency key
     * as the same transmission and return the original outcome when possible.
     */
    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult;
}
