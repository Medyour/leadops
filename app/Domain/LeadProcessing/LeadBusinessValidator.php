<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Data\ValidationResult;
use App\Domain\LeadProcessing\Enums\LeadSource;

final class LeadBusinessValidator
{
    /** @var list<string> */
    private array $recognizedServices;

    /** @param list<string>|null $recognizedServices */
    public function __construct(?array $recognizedServices = null)
    {
        $this->recognizedServices = $recognizedServices
            ?? config('leadops.qualification.services', []);
    }

    /** @param array<string, mixed> $attributes */
    public function validate(array $attributes): ValidationResult
    {
        $invalid = [];
        $review = [];

        if (! in_array($attributes['source'] ?? null, array_column(LeadSource::cases(), 'value'), true)) {
            $invalid[] = 'source.invalid';
        }

        foreach (['external_reference', 'first_name', 'last_name'] as $field) {
            if (($attributes[$field] ?? '') === '') {
                $invalid[] = "{$field}.required";
            }
        }

        if (($attributes['consent'] ?? false) !== true) {
            $invalid[] = 'consent.required';
        }

        $service = $attributes['service_interest'] ?? '';
        if ($service === '') {
            $invalid[] = 'service_interest.required';
        } elseif (! is_string($service) || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $service) !== 1) {
            $invalid[] = 'service_interest.invalid';
        } elseif (! in_array($service, $this->recognizedServices, true)) {
            $review[] = 'service_interest.unknown';
        }

        if (($attributes['normalized_email'] ?? null) === null
            && ($attributes['normalized_phone'] ?? null) === null) {
            $invalid[] = 'contact.invalid';
        }

        $companySize = $attributes['company_size'] ?? null;
        if ($companySize !== null
            && filter_var($companySize, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $invalid[] = 'company_size.invalid';
        }

        return new ValidationResult($invalid, $review);
    }
}
