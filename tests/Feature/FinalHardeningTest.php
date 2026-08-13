<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FinalHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_responses_use_safe_host_independent_security_headers(): void
    {
        foreach ([$this->get('/'), $this->get('/route-that-does-not-exist')] as $response) {
            $response
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
                ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

            $this->assertFalse($response->headers->has('Content-Security-Policy'));
            $this->assertFalse($response->headers->has('Strict-Transport-Security'));
            $this->assertFalse($response->headers->has('X-Powered-By'));
        }
    }

    public function test_a_local_route_and_config_cache_fail_closed_under_a_production_runtime(): void
    {
        $suffix = 'final-hardening-'.bin2hex(random_bytes(4));
        $configCache = "bootstrap/cache/config-{$suffix}.php";
        $routesCache = "bootstrap/cache/routes-{$suffix}.php";
        $cacheEnvironment = [
            'APP_ENV' => 'local',
            'APP_CONFIG_CACHE' => $configCache,
            'APP_ROUTES_CACHE' => $routesCache,
        ];

        try {
            foreach ([['config:cache'], ['route:cache']] as $command) {
                (new Process([PHP_BINARY, 'artisan', ...$command], base_path(), $cacheEnvironment))->mustRun();
            }

            $runtimeEnvironment = [
                ...$cacheEnvironment,
                'APP_ENV' => 'production',
            ];
            $process = new Process([
                PHP_BINARY,
                '-r',
                <<<'PHP'
                    require 'vendor/autoload.php';
                    $app = require 'bootstrap/app.php';
                    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                    foreach ([
                        ['POST', '/api/local/webhooks/leads'],
                        ['GET', '/tools/webhook'],
                        ['GET', '/leads/create'],
                        ['POST', '/leads'],
                        ['GET', '/imports/csv'],
                        ['POST', '/imports/csv'],
                        ['PATCH', '/leads/1/review'],
                        ['POST', '/leads/1/reprocess'],
                        ['POST', '/leads/1/retry'],
                    ] as [$method, $uri]) {
                        $request = Illuminate\Http\Request::create($uri, $method, server: [
                            'CONTENT_TYPE' => 'application/json',
                            'HTTP_ACCEPT' => 'application/json',
                        ]);
                        $response = $kernel->handle($request);
                        echo $response->getStatusCode(), PHP_EOL;
                        $kernel->terminate($request, $response);
                    }
                    PHP,
            ], base_path(), $runtimeEnvironment);
            $process->mustRun();

            $this->assertSame(array_fill(0, 9, '404'), array_values(array_filter(
                preg_split('/\R/', trim($process->getOutput())) ?: [],
            )));
        } finally {
            @unlink(base_path($configCache));
            @unlink(base_path($routesCache));
        }
    }

    public function test_session_payload_encryption_is_enabled_by_default(): void
    {
        $this->assertTrue((bool) config('session.encrypt'));
    }

    public function test_the_local_disk_does_not_register_public_read_or_write_routes(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertNull(app('router')->getRoutes()->getByName('storage.local'));
        $this->assertNull(app('router')->getRoutes()->getByName('storage.local.upload'));

        $this->get('/storage/example.csv')->assertNotFound();
        $this->put('/storage/example.csv')->assertNotFound();
    }
}
