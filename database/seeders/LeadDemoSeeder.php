<?php

namespace Database\Seeders;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeadDemoSeeder extends Seeder
{
    /** @var array<string, string> */
    private const SOURCES = [
        'demo-hot-001' => 'form',
        'demo-warm-001' => 'csv',
        'demo-low-001' => 'webhook',
        'demo-invalid-001' => 'form',
        'demo-invalid-phone-001' => 'csv',
        'demo-duplicate-001' => 'webhook',
        'demo-review-001' => 'form',
        'demo-failed-001' => 'webhook',
    ];

    private DateTimeZone $utc;

    public function run(): void
    {
        $this->utc = new DateTimeZone('UTC');

        DB::transaction(function (): void {
            $ids = [];

            foreach ($this->leads() as $reference => $attributes) {
                $ids[$reference] = $this->upsertLead($reference, $attributes);
            }

            DB::table('leads')->where('id', $ids['demo-duplicate-001'])->update([
                'duplicate_of' => $ids['demo-hot-001'],
            ]);

            foreach ($this->timelines($ids) as $reference => $events) {
                $this->syncTimeline($ids[$reference], $events);
            }
        });
    }

    /** @return array<string, array<string, mixed>> */
    private function leads(): array
    {
        $longMessage = 'Demande entièrement fictive pour illustrer un parcours LeadOps déterministe, explicable et sans aucune donnée client réelle.';

        return [
            'demo-hot-001' => [
                'source' => self::SOURCES['demo-hot-001'],
                'first_name' => 'Aline-Fictive',
                'last_name' => 'Démo',
                'company' => 'Atelier Fictif Démo',
                'email' => 'aline-fictive@example.test',
                'phone' => '+12025550100',
                'country' => 'US',
                'service_interest' => ServiceInterest::Automation->value,
                'company_size' => 24,
                'message' => $longMessage,
                'normalized_email' => 'aline-fictive@example.test',
                'normalized_phone' => '+12025550100',
                'score' => 90,
                'classification' => LeadClassification::Hot->value,
                'assigned_to' => 'Sarah',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:00:00',
                'processed_at' => '2026-01-15 09:00:10',
            ],
            'demo-warm-001' => [
                'source' => self::SOURCES['demo-warm-001'],
                'first_name' => 'Benoît-Fictif',
                'last_name' => 'Démo',
                'company' => 'Bureau Imaginaire Démo',
                'email' => 'benoit-fictif@example.test',
                'phone' => '+12025550101',
                'country' => null,
                'service_interest' => ServiceInterest::Dashboard->value,
                'company_size' => 12,
                'message' => 'Demande fictive de tableau de bord pour la démonstration locale.',
                'normalized_email' => 'benoit-fictif@example.test',
                'normalized_phone' => '+12025550101',
                'score' => 70,
                'classification' => LeadClassification::Warm->value,
                'assigned_to' => 'Yassine',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:10:00',
                'processed_at' => '2026-01-15 09:10:10',
            ],
            'demo-low-001' => [
                'source' => self::SOURCES['demo-low-001'],
                'first_name' => 'Camille-Fictive',
                'last_name' => 'Démo',
                'company' => null,
                'email' => 'camille-fictive@example.test',
                'phone' => null,
                'country' => null,
                'service_interest' => ServiceInterest::Other->value,
                'company_size' => null,
                'message' => 'Demande fictive courte.',
                'normalized_email' => 'camille-fictive@example.test',
                'normalized_phone' => null,
                'score' => 40,
                'classification' => LeadClassification::Low->value,
                'assigned_to' => 'Emma',
                'status' => LeadStatus::Routed->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:20:00',
                'processed_at' => '2026-01-15 09:20:10',
            ],
            'demo-invalid-001' => [
                'source' => self::SOURCES['demo-invalid-001'],
                'first_name' => 'Dorian-Fictif',
                'last_name' => 'Démo',
                'company' => 'Structure Fictive Démo',
                'email' => 'adresse-invalide',
                'phone' => null,
                'country' => 'FR',
                'service_interest' => ServiceInterest::Api->value,
                'company_size' => 8,
                'message' => 'Exemple fictif avec une adresse e-mail invalide.',
                'normalized_email' => null,
                'normalized_phone' => null,
                'status' => LeadStatus::Invalid->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:30:00',
            ],
            'demo-invalid-phone-001' => [
                'source' => self::SOURCES['demo-invalid-phone-001'],
                'first_name' => 'Élodie-Fictive',
                'last_name' => 'Démo',
                'company' => 'Téléphone Fictif Démo',
                'email' => null,
                'phone' => '+1202555019999999',
                'country' => 'US',
                'service_interest' => ServiceInterest::BusinessApplication->value,
                'company_size' => 16,
                'message' => 'Exemple fictif avec un format de téléphone volontairement invalide.',
                'normalized_email' => null,
                'normalized_phone' => null,
                'status' => LeadStatus::Invalid->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:40:00',
            ],
            'demo-duplicate-001' => [
                'source' => self::SOURCES['demo-duplicate-001'],
                'first_name' => 'Farah-Fictive',
                'last_name' => 'Démo',
                'company' => 'Doublon Fictif Démo',
                'email' => 'aline-fictive@example.test',
                'phone' => '+12025550100',
                'country' => 'US',
                'service_interest' => ServiceInterest::Automation->value,
                'company_size' => 24,
                'message' => 'Exemple fictif relié à une origine antérieure.',
                'normalized_email' => 'aline-fictive@example.test',
                'normalized_phone' => '+12025550100',
                'status' => LeadStatus::Duplicate->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 09:50:00',
            ],
            'demo-review-001' => [
                'source' => self::SOURCES['demo-review-001'],
                'first_name' => 'Gaël-Fictif',
                'last_name' => 'Démo',
                'company' => 'Revue Fictive Démo',
                'email' => 'aline-fictive@example.test',
                'phone' => '+12025550101',
                'country' => 'FR',
                'service_interest' => ServiceInterest::Api->value,
                'company_size' => 18,
                'message' => 'Exemple fictif ambigu, prévu pour une correction manuelle locale.',
                'normalized_email' => 'aline-fictive@example.test',
                'normalized_phone' => '+12025550101',
                'status' => LeadStatus::NeedsReview->value,
                'processing_attempts' => 1,
                'received_at' => '2026-01-15 10:00:00',
            ],
            'demo-failed-001' => [
                'source' => self::SOURCES['demo-failed-001'],
                'first_name' => 'Hana-Fictive',
                'last_name' => 'Démo',
                'company' => 'Incident Fictif Démo',
                'email' => 'hana-fictive@example.test',
                'phone' => '+12025550106',
                'country' => 'US',
                'service_interest' => ServiceInterest::Api->value,
                'company_size' => 20,
                'message' => $longMessage,
                'normalized_email' => 'hana-fictive@example.test',
                'normalized_phone' => '+12025550106',
                'score' => 90,
                'classification' => LeadClassification::Hot->value,
                'assigned_to' => 'Sarah',
                'status' => LeadStatus::Failed->value,
                'processing_attempts' => 3,
                'received_at' => '2026-01-15 10:10:00',
            ],
        ];
    }

    /** @param array<string, int> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    private function timelines(array $ids): array
    {
        $receipt = fn (string $reference): array => $this->event(
            ProcessingEventType::LeadReceived,
            null,
            LeadStatus::Received,
            null,
            ['reference_fingerprint' => $this->receiptFingerprint($reference)],
        );
        $routed = function (string $reference, int $score, LeadClassification $classification, string $service, string $assignee) use ($ids): array {
            $ruleCodes = match ($reference) {
                'demo-warm-001' => [
                    'base:+20', 'company_size_present:+10', 'company_size_at_least_five:+10',
                    'valid_email:+10', 'valid_phone:+10', 'recognized_service:+20',
                    'missing_company_or_country:-10',
                ],
                'demo-low-001' => [
                    'base:+20', 'valid_email:+10', 'recognized_service:+20',
                    'missing_company_or_country:-10',
                ],
                default => [
                    'base:+20', 'company_size_present:+10', 'company_size_at_least_five:+10',
                    'valid_email:+10', 'valid_phone:+10', 'recognized_service:+20', 'long_message:+10',
                ],
            };

            return [
                $this->event(ProcessingEventType::LeadReceived, null, LeadStatus::Received, null, ['reference_fingerprint' => $this->receiptFingerprint($reference)]),
                $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Received, LeadStatus::Validating, 1),
                $this->event(ProcessingEventType::LeadNormalized, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::ValidationSucceeded, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::DuplicateChecked, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::ScoreCalculated, LeadStatus::Validating, null, 1, ['score' => $score, 'rule_codes' => $ruleCodes]),
                $this->event(ProcessingEventType::LeadClassified, LeadStatus::Validating, null, 1, ['classification' => $classification->value]),
                $this->event(ProcessingEventType::LeadRoutingDecided, LeadStatus::Validating, null, 1, ['service' => $service, 'assigned_to' => $assignee]),
                $this->event(ProcessingEventType::LeadQualified, LeadStatus::Validating, LeadStatus::Qualified, 1),
                $this->event(ProcessingEventType::TransmissionSucceeded, LeadStatus::Qualified, null, 1, ['reference_fingerprint' => $this->transmissionFingerprint($ids[$reference]), 'idempotency_key' => $this->transmissionIdempotencyKey($ids[$reference])]),
                $this->event(ProcessingEventType::LeadRouted, LeadStatus::Qualified, LeadStatus::Routed, 1, ['assigned_to' => $assignee]),
            ];
        };

        $invalid = fn (string $reference): array => [
            $receipt($reference),
            $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Received, LeadStatus::Validating, 1),
            $this->event(ProcessingEventType::LeadNormalized, LeadStatus::Validating, null, 1),
            $this->event(ProcessingEventType::ValidationFailed, LeadStatus::Validating, LeadStatus::Invalid, 1, ['reason_codes' => ['contact.invalid']]),
        ];

        $failed = $routed('demo-failed-001', 90, LeadClassification::Hot, ServiceInterest::Api->value, 'Sarah');
        array_splice($failed, -2);
        $failed = [
            ...$failed,
            $this->event(ProcessingEventType::TransmissionFailed, LeadStatus::Qualified, null, 1, ['error_code' => 'transmission_failed'], 9),
            $this->event(ProcessingEventType::ProcessingRetryScheduled, LeadStatus::Qualified, null, 1, ['error_code' => 'transmission_failed', 'retry_delay' => 1], 10),
            $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Qualified, null, 2, offsetSeconds: 11),
            $this->event(ProcessingEventType::TransmissionFailed, LeadStatus::Qualified, null, 2, ['error_code' => 'transmission_failed'], 12),
            $this->event(ProcessingEventType::ProcessingRetryScheduled, LeadStatus::Qualified, null, 2, ['error_code' => 'transmission_failed', 'retry_delay' => 5], 13),
            $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Qualified, null, 3, offsetSeconds: 18),
            $this->event(ProcessingEventType::TransmissionFailed, LeadStatus::Qualified, null, 3, ['error_code' => 'transmission_failed'], 19),
            $this->event(ProcessingEventType::ProcessingFailed, LeadStatus::Qualified, LeadStatus::Failed, 3, ['error_code' => 'attempts_exhausted'], 20),
        ];

        $reviewCandidateIds = [$ids['demo-hot-001'], $ids['demo-warm-001']];
        sort($reviewCandidateIds);

        return [
            'demo-hot-001' => $routed('demo-hot-001', 90, LeadClassification::Hot, ServiceInterest::Automation->value, 'Sarah'),
            'demo-warm-001' => $routed('demo-warm-001', 70, LeadClassification::Warm, ServiceInterest::Dashboard->value, 'Yassine'),
            'demo-low-001' => $routed('demo-low-001', 40, LeadClassification::Low, ServiceInterest::Other->value, 'Emma'),
            'demo-invalid-001' => $invalid('demo-invalid-001'),
            'demo-invalid-phone-001' => $invalid('demo-invalid-phone-001'),
            'demo-duplicate-001' => [
                $receipt('demo-duplicate-001'),
                $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Received, LeadStatus::Validating, 1),
                $this->event(ProcessingEventType::LeadNormalized, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::ValidationSucceeded, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::DuplicateDetected, LeadStatus::Validating, LeadStatus::Duplicate, 1, ['candidate_ids' => [$ids['demo-hot-001']]]),
            ],
            'demo-review-001' => [
                $receipt('demo-review-001'),
                $this->event(ProcessingEventType::ProcessingStarted, LeadStatus::Received, LeadStatus::Validating, 1),
                $this->event(ProcessingEventType::LeadNormalized, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::ValidationSucceeded, LeadStatus::Validating, null, 1),
                $this->event(ProcessingEventType::ReviewRequested, LeadStatus::Validating, LeadStatus::NeedsReview, 1, ['reason_codes' => ['duplicate.ambiguous'], 'candidate_ids' => $reviewCandidateIds]),
            ],
            'demo-failed-001' => $failed,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function upsertLead(string $reference, array $attributes): int
    {
        $existing = DB::table('leads')
            ->where('source', $attributes['source'])
            ->where('external_reference', $reference)
            ->first();

        if ($existing === null && $attributes['source'] !== LeadSource::Form->value) {
            $legacy = DB::table('leads')
                ->where('source', LeadSource::Form->value)
                ->where('external_reference', $reference)
                ->first();

            if ($legacy !== null && $this->isLegacyDemoLead($legacy, $reference)) {
                $existing = $legacy;
            }
        }

        $timestamp = $attributes['received_at'];
        $values = [
            ...$attributes,
            'external_reference' => $reference,
            'consent' => true,
            'duplicate_of' => null,
            'score' => $attributes['score'] ?? null,
            'classification' => $attributes['classification'] ?? null,
            'assigned_to' => $attributes['assigned_to'] ?? null,
            'processed_at' => $attributes['processed_at'] ?? null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($existing !== null) {
            DB::table('leads')->where('id', $existing->id)->update($values);

            return (int) $existing->id;
        }

        return (int) DB::table('leads')->insertGetId($values);
    }

    /** @param list<array<string, mixed>> $events */
    private function syncTimeline(int $leadId, array $events): void
    {
        $existingIds = DB::table('processing_events')
            ->where('lead_id', $leadId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $base = new DateTimeImmutable(
            (string) DB::table('leads')->where('id', $leadId)->value('received_at'),
            $this->utc,
        );

        foreach ($events as $position => $event) {
            $offsetSeconds = $event['offset_seconds'] ?? $position;
            unset($event['offset_seconds']);
            $values = [
                ...$event,
                'lead_id' => $leadId,
                'context' => json_encode($event['context'], JSON_THROW_ON_ERROR),
                'created_at' => $base->modify(sprintf('+%d seconds', $offsetSeconds))->format('Y-m-d H:i:s'),
            ];

            if (isset($existingIds[$position])) {
                DB::table('processing_events')->where('id', $existingIds[$position])->update($values);
            } else {
                DB::table('processing_events')->insert($values);
            }
        }

        $obsoleteIds = array_slice($existingIds, count($events));
        if ($obsoleteIds !== []) {
            DB::table('processing_events')->whereIn('id', $obsoleteIds)->delete();
        }
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function event(
        ProcessingEventType $type,
        ?LeadStatus $fromStatus,
        ?LeadStatus $toStatus,
        ?int $attempt,
        array $context = [],
        ?int $offsetSeconds = null,
    ): array {
        $event = [
            'type' => $type->value,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'attempt' => $attempt,
            'context' => $context,
        ];

        if ($offsetSeconds !== null) {
            $event['offset_seconds'] = $offsetSeconds;
        }

        return $event;
    }

    private function isLegacyDemoLead(object $lead, string $reference): bool
    {
        $signatures = [
            'demo-warm-001' => [
                'first_name' => 'Benoit-Fictif', 'last_name' => 'Demo', 'company' => 'Bureau Imaginaire',
                'email' => 'benoit-fictif@example.test', 'phone' => '+12025550101', 'country' => 'FR',
                'service_interest' => 'dashboard', 'company_size' => 12, 'normalized_email' => 'benoit-fictif@example.test',
                'normalized_phone' => '+12025550101', 'score' => 65, 'classification' => 'WARM',
                'assigned_to' => 'Yassine', 'status' => 'ROUTED', 'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:06:00',
            ],
            'demo-low-001' => [
                'first_name' => 'Camille-Fictive', 'last_name' => 'Demo', 'company' => null,
                'email' => 'camille-fictive@example.test', 'phone' => '+12025550102', 'country' => null,
                'service_interest' => 'other', 'company_size' => null, 'normalized_email' => 'camille-fictive@example.test',
                'normalized_phone' => '+12025550102', 'score' => 35, 'classification' => 'LOW',
                'assigned_to' => 'Emma', 'status' => 'ROUTED', 'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:07:00',
            ],
            'demo-duplicate-001' => [
                'first_name' => 'Elise-Fictive', 'last_name' => 'Demo', 'company' => 'Doublon Fictif',
                'email' => 'aline-fictive@example.test', 'phone' => '+12025550100', 'country' => 'FR',
                'service_interest' => 'automation', 'company_size' => 12, 'normalized_email' => 'aline-fictive@example.test',
                'normalized_phone' => '+12025550100', 'score' => null, 'classification' => null,
                'assigned_to' => null, 'status' => 'DUPLICATE', 'processing_attempts' => 0,
                'processed_at' => null,
            ],
            'demo-failed-001' => [
                'first_name' => 'Gaelle-Fictive', 'last_name' => 'Demo', 'company' => 'Incident Fictif',
                'email' => 'gaelle-fictive@example.test', 'phone' => '+12025550106', 'country' => 'FR',
                'service_interest' => 'automation', 'company_size' => 12, 'normalized_email' => 'gaelle-fictive@example.test',
                'normalized_phone' => '+12025550106', 'score' => null, 'classification' => null,
                'assigned_to' => null, 'status' => 'FAILED', 'processing_attempts' => 3,
                'processed_at' => null,
            ],
        ];

        $signature = $signatures[$reference] ?? null;
        if ($signature === null
            || $lead->received_at !== '2026-01-15 09:00:00'
            || (int) $lead->consent !== 1
            || $lead->message !== 'Message entièrement fictif destiné à la démonstration locale LeadOps.') {
            return false;
        }

        foreach ($signature as $field => $value) {
            if ($lead->{$field} !== $value) {
                return false;
            }
        }

        return true;
    }

    private function receiptFingerprint(string $reference): string
    {
        return $this->fingerprint(self::SOURCES[$reference].'|'.$reference);
    }

    private function transmissionIdempotencyKey(int $leadId): string
    {
        return "lead:{$leadId}:cycle:initial:transmission";
    }

    private function transmissionFingerprint(int $leadId): string
    {
        $idempotencyKey = $this->transmissionIdempotencyKey($leadId);
        $reference = sprintf(
            '%s-%s',
            config('leadops.transmission.reference_prefix', 'simulated'),
            substr(hash('sha256', $idempotencyKey), 0, 16),
        );

        return $this->fingerprint($reference);
    }

    private function fingerprint(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}
