<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Data\ScoreResult;

final class LeadScorer
{
    /**
     * @param  array<string, mixed>|null  $scoreConfig
     * @param  list<string>|null  $recognizedServices
     */
    public function __construct(
        private ?array $scoreConfig = null,
        private ?array $recognizedServices = null,
    ) {
        $this->scoreConfig ??= config('leadops.score');
        $this->recognizedServices ??= config('leadops.qualification.services', []);
    }

    /** @param array<string, mixed> $attributes */
    public function score(array $attributes): ScoreResult
    {
        $score = (int) $this->scoreConfig['base'];
        $ruleCodes = ["base:+{$score}"];
        $weights = $this->scoreConfig['weights'];

        $this->apply($score, $ruleCodes, $attributes['company_size'] !== null, 'company_size_present', $weights);
        $this->apply($score, $ruleCodes, (int) ($attributes['company_size'] ?? 0) >= 5, 'company_size_at_least_five', $weights);
        $this->apply($score, $ruleCodes, $attributes['normalized_email'] !== null, 'valid_email', $weights);
        $this->apply($score, $ruleCodes, $attributes['normalized_phone'] !== null, 'valid_phone', $weights);
        $this->apply(
            $score,
            $ruleCodes,
            in_array($attributes['service_interest'], $this->recognizedServices, true),
            'recognized_service',
            $weights,
        );
        $this->apply(
            $score,
            $ruleCodes,
            mb_strlen((string) ($attributes['message'] ?? '')) >= (int) $this->scoreConfig['long_message_threshold'],
            'long_message',
            $weights,
        );

        $emailDomain = $attributes['normalized_email'] === null
            ? null
            : substr(strrchr($attributes['normalized_email'], '@') ?: '', 1);
        $this->apply(
            $score,
            $ruleCodes,
            $emailDomain !== null && in_array($emailDomain, $this->scoreConfig['generic_email_domains'], true),
            'generic_email_domain',
            $weights,
        );
        $this->apply(
            $score,
            $ruleCodes,
            $attributes['company'] === null || $attributes['country'] === null,
            'missing_company_or_country',
            $weights,
        );

        return new ScoreResult(
            max((int) $this->scoreConfig['minimum'], min((int) $this->scoreConfig['maximum'], $score)),
            $ruleCodes,
        );
    }

    /**
     * @param  list<string>  $ruleCodes
     * @param  array<string, int>  $weights
     */
    private function apply(int &$score, array &$ruleCodes, bool $condition, string $rule, array $weights): void
    {
        if (! $condition) {
            return;
        }

        $weight = $weights[$rule];
        $score += $weight;
        $ruleCodes[] = sprintf('%s:%+d', $rule, $weight);
    }
}
