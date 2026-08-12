<?php

namespace App\Domain\LeadProcessing;

final class LeadNormalizer
{
    /** @var array<string, string> */
    private array $countryCallingCodes;

    /** @param array<string, string>|null $countryCallingCodes */
    public function __construct(?array $countryCallingCodes = null)
    {
        $this->countryCallingCodes = $countryCallingCodes
            ?? config('leadops.normalization.phone_country_calling_codes', []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalize(array $attributes): array
    {
        $country = $this->optionalText($attributes['country'] ?? null);
        $country = $country === null ? null : strtoupper($country);
        $email = $this->optionalText($attributes['email'] ?? null);
        $email = $email === null ? null : strtolower($email);
        [$phone, $normalizedPhone] = $this->normalizePhone(
            $attributes['phone'] ?? null,
            $country,
        );

        return [
            'source' => $this->requiredText($attributes['source'] ?? null),
            'external_reference' => $this->requiredText($attributes['external_reference'] ?? null),
            'first_name' => $this->requiredText($attributes['first_name'] ?? null, true),
            'last_name' => $this->requiredText($attributes['last_name'] ?? null, true),
            'company' => $this->optionalText($attributes['company'] ?? null, true),
            'email' => $email,
            'phone' => $phone,
            'country' => $country,
            'service_interest' => $this->requiredText($attributes['service_interest'] ?? null),
            'company_size' => $attributes['company_size'] ?? null,
            'message' => $this->optionalText($attributes['message'] ?? null, true),
            'consent' => (bool) ($attributes['consent'] ?? false),
            'normalized_email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
                ? $email
                : null,
            'normalized_phone' => $normalizedPhone,
        ];
    }

    private function requiredText(mixed $value, bool $collapseSpaces = false): string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        return $collapseSpaces ? $this->collapseSpaces($text) : $text;
    }

    private function optionalText(mixed $value, bool $collapseSpaces = false): ?string
    {
        $text = $this->requiredText($value, $collapseSpaces);

        return $text === '' ? null : $text;
    }

    private function collapseSpaces(string $value): string
    {
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function normalizePhone(mixed $value, ?string $country): array
    {
        $phone = $this->optionalText($value);

        if ($phone === null) {
            return [null, null];
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return [$phone, null];
        }

        if (str_starts_with($phone, '+')) {
            $canonical = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $canonical = '+'.substr($digits, 2);
        } elseif ($country !== null && isset($this->countryCallingCodes[$country])) {
            $callingCode = $this->countryCallingCodes[$country];
            $nationalNumber = str_starts_with($digits, $callingCode)
                ? $digits
                : $callingCode.ltrim($digits, '0');
            $canonical = '+'.$nationalNumber;
        } else {
            $canonical = $digits;
        }

        $normalized = preg_match('/^\+?[1-9]\d{6,14}$/', $canonical) === 1
            ? $canonical
            : null;

        return [$canonical, $normalized];
    }
}
