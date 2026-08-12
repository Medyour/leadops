<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_returns_201_and_dispatches_one_after_commit_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/local/webhooks/leads', $this->validEnvelope());

        $lead = Lead::query()->sole();
        $response->assertCreated()->assertExactJson([
            'result' => 'created',
            'lead' => [
                'id' => $lead->id,
                'status' => LeadStatus::Received->value,
            ],
        ]);
        $this->assertSame(LeadSource::Webhook, $lead->source);
        $this->assertSame('2026-08-12 10:15:30', $lead->received_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('processing_events', 1);
        $this->assertSame(ProcessingEventType::LeadReceived, $lead->processingEvents()->sole()->type);
        Queue::assertPushed(ProcessLead::class, fn (ProcessLead $job): bool => $job->leadId === $lead->id
            && $job->afterCommit);
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_repetition_returns_200_with_the_same_lead_without_a_second_job_or_receipt_event(): void
    {
        Queue::fake();
        $envelope = $this->validEnvelope();

        $this->postJson('/api/local/webhooks/leads', $envelope)->assertCreated();
        $lead = Lead::query()->sole();

        $envelope['lead']['first_name'] = 'Valeur ignorée lors de la répétition';
        $response = $this->postJson('/api/local/webhooks/leads', $envelope);

        $response->assertOk()->assertExactJson([
            'result' => 'already_received',
            'lead' => [
                'id' => $lead->id,
                'status' => LeadStatus::Received->value,
            ],
        ]);
        $this->assertSame('Prénom Fictif', $lead->fresh()->first_name);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_client_source_is_accepted_but_always_replaced_with_webhook(): void
    {
        Queue::fake();
        $envelope = $this->validEnvelope();
        $envelope['lead']['source'] = 'form';

        $this->postJson('/api/local/webhooks/leads', $envelope)->assertCreated();

        $this->assertSame(LeadSource::Webhook, Lead::query()->sole()->source);
    }

    /** @param array<string, mixed>|string $body */
    #[DataProvider('invalidEnvelopeProvider')]
    public function test_invalid_envelopes_return_safe_422_without_writes_or_dispatch(array|string $body): void
    {
        Queue::fake();

        $response = is_string($body)
            ? $this->call('POST', '/api/local/webhooks/leads', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], content: $body)
            : $this->postJson('/api/local/webhooks/leads', $body);

        $response->assertUnprocessable()->assertJsonPath('result', 'invalid');
        $this->assertIsArray($response->json('errors'));
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        Queue::assertNothingPushed();
    }

    /** @return iterable<string, array{array<string, mixed>|string}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        $valid = self::validEnvelopeData();

        yield 'malformed JSON' => ['{"lead":'];
        yield 'unexpected root key' => [[...$valid, 'metadata' => []]];
        yield 'unexpected lead key' => [['lead' => [...$valid['lead'], 'secret' => 'forbidden']]];
        yield 'client source has wrong type' => [['lead' => [...$valid['lead'], 'source' => ['form']]]];
        yield 'missing external reference' => [['lead' => array_diff_key($valid['lead'], ['external_reference' => true])]];
        yield 'invalid external reference format' => [['lead' => [...$valid['lead'], 'external_reference' => ' spaces are rejected ']]];
        yield 'contact missing' => [['lead' => [...$valid['lead'], 'email' => null, 'phone' => null]]];
        yield 'invalid email' => [['lead' => [...$valid['lead'], 'email' => 'invalid', 'phone' => null]]];
        yield 'invalid phone' => [['lead' => [...$valid['lead'], 'email' => null, 'phone' => '123']]];
        yield 'consent false' => [['lead' => [...$valid['lead'], 'consent' => false]]];
        yield 'consent non boolean' => [['lead' => [...$valid['lead'], 'consent' => 1]]];
        yield 'company size non integer' => [['lead' => [...$valid['lead'], 'company_size' => '12']]];
        yield 'country not uppercase ISO shape' => [['lead' => [...$valid['lead'], 'country' => 'fr']]];
        yield 'service format invalid' => [['lead' => [...$valid['lead'], 'service_interest' => 'Automation API']]];
        yield 'date not UTC Z format' => [['lead' => [...$valid['lead'], 'received_at' => '2026-08-12T12:15:30+02:00']]];
        yield 'first name too long' => [['lead' => [...$valid['lead'], 'first_name' => str_repeat('a', 101)]]];
        yield 'last name has wrong type' => [['lead' => [...$valid['lead'], 'last_name' => 123]]];
        yield 'company too long' => [['lead' => [...$valid['lead'], 'company' => str_repeat('a', 151)]]];
        yield 'email too long' => [['lead' => [...$valid['lead'], 'email' => str_repeat('a', 245).'@example.test']]];
        yield 'phone too long' => [['lead' => [...$valid['lead'], 'email' => null, 'phone' => '+'.str_repeat('1', 50)]]];
        yield 'message too long' => [['lead' => [...$valid['lead'], 'message' => str_repeat('a', 5001)]]];
    }

    public function test_non_json_input_is_rejected_even_when_its_fields_would_otherwise_be_valid(): void
    {
        Queue::fake();

        $response = $this->post('/api/local/webhooks/leads', $this->validEnvelope());

        $response->assertUnprocessable()->assertExactJson([
            'result' => 'invalid',
            'errors' => ['envelope'],
        ]);
        $this->assertDatabaseCount('leads', 0);
        Queue::assertNothingPushed();
    }

    public function test_internal_failure_returns_no_exception_trace_or_sensitive_data(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_webhook_receipt
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'lead.received'
            BEGIN
                SELECT RAISE(ABORT, 'private-webhook@example.test simulated failure');
            END
            SQL);

        try {
            $response = $this->postJson('/api/local/webhooks/leads', $this->validEnvelope());

            $response->assertStatus(500)->assertExactJson(['result' => 'unavailable']);
            $this->assertStringNotContainsString('private-webhook@example.test', $response->getContent());
            $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
            $this->assertStringNotContainsString('exception', strtolower($response->getContent()));
            $this->assertDatabaseCount('leads', 0);
            $this->assertDatabaseCount('processing_events', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_webhook_receipt');
        }
    }

    public function test_success_and_validation_responses_never_expose_sensitive_input(): void
    {
        Queue::fake();
        $envelope = $this->validEnvelope();

        $response = $this->postJson('/api/local/webhooks/leads', $envelope);
        $content = $response->getContent();

        foreach (['private-webhook@example.test', '+12025550123', 'Message entièrement fictif', 'Prénom Fictif'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $content);
        }

        $invalid = $this->postJson('/api/local/webhooks/leads', [
            'lead' => [...$envelope['lead'], 'external_reference' => 'private reference with spaces'],
        ]);
        $invalid->assertUnprocessable();
        $this->assertStringNotContainsString('private reference with spaces', $invalid->getContent());
        $this->assertStringNotContainsString('trace', strtolower($invalid->getContent()));
        $this->assertStringNotContainsString('exception', strtolower($invalid->getContent()));
    }

    public function test_dedicated_rate_limit_uses_an_hmac_key_without_raw_ip_or_personal_data(): void
    {
        config()->set('leadops.local_webhook.rate_limit_per_minute', 2);

        $clientIp = '192.0.2.88';
        $personalEmail = 'private-rate-key@example.test';
        $request = Request::create('/api/local/webhooks/leads', 'POST', [
            'email' => $personalEmail,
        ], server: ['REMOTE_ADDR' => $clientIp]);
        $limit = RateLimiter::limiter('local-webhook')($request);

        $this->assertSame(2, $limit->maxAttempts);
        $this->assertStringNotContainsString($clientIp, $limit->key);
        $this->assertStringNotContainsString($personalEmail, $limit->key);
        $this->assertMatchesRegularExpression('/^local-webhook:[a-f0-9]{64}$/', $limit->key);

        Queue::fake();
        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $envelope = $this->validEnvelope();
            $envelope['lead']['external_reference'] = 'webhook-rate-'.$attempt;
            $this->postJson('/api/local/webhooks/leads', $envelope)->assertCreated();
        }

        $envelope = $this->validEnvelope();
        $envelope['lead']['external_reference'] = 'webhook-rate-3';
        $this->postJson('/api/local/webhooks/leads', $envelope)->assertTooManyRequests();
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_route_has_no_session_or_csrf_dependency(): void
    {
        Queue::fake();
        $route = app('router')->getRoutes()->getByName('local.webhooks.leads.store');

        $this->assertNotNull($route);
        $this->assertSame(['api', 'throttle:local-webhook'], $route->gatherMiddleware());

        $response = $this->postJson('/api/local/webhooks/leads', $this->validEnvelope());

        $response->assertCreated();
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_route_is_registered_in_testing_and_local_only(): void
    {
        $this->assertNotNull(app('router')->getRoutes()->getByName('local.webhooks.leads.store'));
        $this->assertContains('local.webhooks.leads.store', $this->routeNamesForEnvironment('local'));
        $this->assertNotContains('local.webhooks.leads.store', $this->routeNamesForEnvironment('production'));
        $this->assertNotContains('local.webhooks.leads.store', $this->routeNamesForEnvironment('staging'));
    }

    /** @return array<string, mixed> */
    private function validEnvelope(): array
    {
        return self::validEnvelopeData();
    }

    /** @return array<string, mixed> */
    private static function validEnvelopeData(): array
    {
        return [
            'lead' => [
                'external_reference' => 'webhook-2026-0001',
                'first_name' => 'Prénom Fictif',
                'last_name' => 'Nom Fictif',
                'company' => 'Atelier Imaginaire',
                'email' => 'private-webhook@example.test',
                'phone' => '+12025550123',
                'country' => 'FR',
                'service_interest' => 'automation',
                'company_size' => 12,
                'message' => 'Message entièrement fictif pour valider le webhook LeadOps.',
                'consent' => true,
                'received_at' => '2026-08-12T10:15:30Z',
            ],
        ];
    }

    /** @return list<string|null> */
    private function routeNamesForEnvironment(string $environment): array
    {
        $process = new Process([
            PHP_BINARY,
            'artisan',
            'route:list',
            '--json',
        ], base_path(), [
            'APP_ENV' => $environment,
            'APP_CONFIG_CACHE' => base_path("bootstrap/cache/config-{$environment}-test.php"),
            'APP_ROUTES_CACHE' => base_path("bootstrap/cache/routes-{$environment}-test.php"),
        ]);
        $process->mustRun();

        return array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name');
    }
}
