<?php

namespace Tests\Feature;

use App\Jobs\ProcessLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebhookSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulator_page_is_available_in_testing_with_a_server_owned_endpoint(): void
    {
        $relativeEndpoint = route('local.webhooks.leads.store', absolute: false);
        $response = $this->get('/tools/webhook');

        $response->assertOk()
            ->assertViewIs('tools.webhook')
            ->assertViewHas('webhookUrl', $relativeEndpoint)
            ->assertSee('data-webhook-simulator', false)
            ->assertSee('data-endpoint="'.e($relativeEndpoint).'"', false)
            ->assertSee('Laravel reste l’unique autorité de validation')
            ->assertDontSee('name="endpoint"', false);

        $this->assertSame('/api/local/webhooks/leads', $relativeEndpoint);
        $this->assertStringNotContainsString('://', $relativeEndpoint);
    }

    public function test_blade_escapes_the_laravel_endpoint_before_passing_it_to_vue(): void
    {
        $maliciousEndpoint = '"><script>window.compromised=true</script>';
        $html = view('tools.webhook', ['webhookUrl' => $maliciousEndpoint])->render();

        $this->assertStringContainsString('data-endpoint="'.e($maliciousEndpoint).'"', $html);
        $this->assertStringNotContainsString('<script>window.compromised', $html);
    }

    public function test_navigation_shows_the_simulator_only_when_its_route_exists(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('tools.webhook'), false)
            ->assertSee('Simulateur webhook');

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('LocalEnvironment::allowsMutations()', $layout);
        $this->assertStringContainsString("Route::has('tools.webhook')", $layout);
    }

    public function test_real_webhook_path_returns_created_then_already_received_for_the_same_reference(): void
    {
        Queue::fake();
        $payload = [
            'lead' => [
                'source' => 'form',
                'external_reference' => 'webhook-simulator-idempotent-001',
                'first_name' => 'Prénom',
                'last_name' => 'Exemple',
                'company' => 'Atelier Exemple',
                'email' => 'webhook@example.test',
                'phone' => '+12025550142',
                'country' => 'FR',
                'service_interest' => 'automation',
                'company_size' => 12,
                'message' => 'Demande entièrement fictive pour tester le parcours du simulateur.',
                'consent' => true,
                'received_at' => '2026-08-13T10:15:30Z',
            ],
        ];

        $created = $this->postJson('/api/local/webhooks/leads', $payload);
        $alreadyReceived = $this->postJson('/api/local/webhooks/leads', $payload);

        $created->assertCreated()->assertJsonPath('result', 'created');
        $alreadyReceived->assertOk()
            ->assertJsonPath('result', 'already_received')
            ->assertJsonPath('lead.id', $created->json('lead.id'));
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_page_and_api_routes_are_registered_only_in_local_and_testing(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $routeNames = $this->routeNamesForEnvironment($environment);
            $this->assertContains('tools.webhook', $routeNames);
            $this->assertContains('local.webhooks.leads.store', $routeNames);
        }

        foreach (['production', 'staging'] as $environment) {
            $routeNames = $this->routeNamesForEnvironment($environment);
            $this->assertNotContains('tools.webhook', $routeNames);
            $this->assertNotContains('local.webhooks.leads.store', $routeNames);
        }
    }

    public function test_cached_routes_keep_the_page_and_api_unavailable_outside_allowed_environments(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $state = $this->cachedApplicationStateForEnvironment($environment);
            $this->assertContains('tools.webhook', $state['route_names']);
            $this->assertContains('local.webhooks.leads.store', $state['route_names']);
            $this->assertStringContainsString('Simulateur webhook', $state['layout']);
        }

        foreach (['production', 'staging'] as $environment) {
            $state = $this->cachedApplicationStateForEnvironment($environment);
            $this->assertNotContains('tools.webhook', $state['route_names']);
            $this->assertNotContains('local.webhooks.leads.store', $state['route_names']);
            $this->assertStringNotContainsString('Simulateur webhook', $state['layout']);
        }
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
            'APP_CONFIG_CACHE' => base_path("bootstrap/cache/config-{$environment}-simulator-test.php"),
            'APP_ROUTES_CACHE' => base_path("bootstrap/cache/routes-{$environment}-simulator-test.php"),
        ]);
        $process->mustRun();

        return array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name');
    }

    /** @return array{route_names: list<string|null>, layout: string} */
    private function cachedApplicationStateForEnvironment(string $environment): array
    {
        $suffix = $environment.'-simulator-'.bin2hex(random_bytes(4));
        $configCache = "bootstrap/cache/config-{$suffix}.php";
        $routesCache = "bootstrap/cache/routes-{$suffix}.php";
        $processEnvironment = [
            'APP_ENV' => $environment,
            'APP_CONFIG_CACHE' => $configCache,
            'APP_ROUTES_CACHE' => $routesCache,
        ];

        try {
            foreach ([['config:cache'], ['route:cache']] as $command) {
                $process = new Process([PHP_BINARY, 'artisan', ...$command], base_path(), $processEnvironment);
                $process->mustRun();
            }

            $configuration = require base_path($configCache);
            $this->assertSame($environment, $configuration['app']['env']);

            $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], base_path(), $processEnvironment);
            $process->mustRun();
            $layoutProcess = new Process([
                PHP_BINARY,
                '-r',
                <<<'PHP'
                    require 'vendor/autoload.php';
                    $app = require 'bootstrap/app.php';
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    $app->instance('request', Illuminate\Http\Request::create('/', 'GET'));
                    echo view('layouts.app')->render();
                    PHP,
            ], base_path(), $processEnvironment);
            $layoutProcess->mustRun();

            return [
                'route_names' => array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name'),
                'layout' => $layoutProcess->getOutput(),
            ];
        } finally {
            @unlink(base_path($configCache));
            @unlink(base_path($routesCache));
        }
    }
}
