<?php

namespace App\Domain\LeadProcessing\Data;

final readonly class TransmissionResult
{
    private function __construct(
        public bool $successful,
        public ?string $reference,
        public ?string $errorCode,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference, null);
    }

    public static function failed(string $errorCode = 'transmission_unavailable'): self
    {
        return new self(false, null, $errorCode);
    }
}
