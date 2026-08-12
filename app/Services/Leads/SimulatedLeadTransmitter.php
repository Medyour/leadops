<?php

namespace App\Services\Leads;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Domain\LeadProcessing\Data\TransmissionResult;
use App\Models\Lead;

final class SimulatedLeadTransmitter implements LeadTransmitter
{
    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult
    {
        $prefix = config('leadops.transmission.reference_prefix', 'simulated');
        $reference = sprintf('%s-%s', $prefix, substr(hash('sha256', $idempotencyKey), 0, 16));

        return TransmissionResult::succeeded($reference);
    }
}
