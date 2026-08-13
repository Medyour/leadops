<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebhookSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulator_page_is_available_in_testing_with_a_server_owned_endpoint(): void
    {
        $response = $this->get('/tools/webhook');

        $response->assertOk()
            ->assertViewIs('tools.webhook')
            ->assertViewHas('webhookUrl', route('local.webhooks.leads.store'))
            ->assertSee('data-webhook-simulator', false)
            ->assertSee('data-endpoint="'.e(route('local.webhooks.leads.store')).'"', false)
            ->assertSee('Laravel reste l’unique autorité de validation')
            ->assertDontSee('name="endpoint"', false);
    }

    public function test_navigation_shows_the_simulator_only_when_its_route_exists(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('tools.webhook'), false)
            ->assertSee('Simulateur webhook');

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("Route::has('tools.webhook')", $layout);
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
            $routeNames = $this->cachedRouteNamesForEnvironment($environment);
            $this->assertContains('tools.webhook', $routeNames);
            $this->assertContains('local.webhooks.leads.store', $routeNames);
        }

        foreach (['production', 'staging'] as $environment) {
            $routeNames = $this->cachedRouteNamesForEnvironment($environment);
            $this->assertNotContains('tools.webhook', $routeNames);
            $this->assertNotContains('local.webhooks.leads.store', $routeNames);
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

    /** @return list<string|null> */
    private function cachedRouteNamesForEnvironment(string $environment): array
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

            $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], base_path(), $processEnvironment);
            $process->mustRun();

            return array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name');
        } finally {
            @unlink(base_path($configCache));
            @unlink(base_path($routesCache));
        }
    }
}
