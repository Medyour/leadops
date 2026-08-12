<?php

namespace App\Domain\LeadProcessing\Exceptions;

use RuntimeException;

final class LeadTransmissionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('The simulated lead transmission failed.');
    }
}
