<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionDeploymentTest extends TestCase
{
    public function test_app_spec_describes_only_the_minimal_manual_read_only_service(): void
    {
        $spec = file_get_contents(base_path('.do/app.yaml'));

        $this->assertIsString($spec);
        $this->assertStringContainsString('repo: Medyour/leadops', $spec);
        $this->assertStringContainsString('branch: main', $spec);
        $this->assertStringContainsString('deploy_on_push: false', $spec);
        $this->assertStringContainsString('environment_slug: php', $spec);
        $this->assertStringContainsString('instance_count: 1', $spec);
        $this->assertStringContainsString('instance_size_slug: apps-s-1vcpu-0.5gb', $spec);
        $this->assertStringContainsString('http_path: /health', $spec);
        $this->assertStringContainsString('DB_READ_ONLY', $spec);
        $this->assertStringContainsString('value: "true"', $spec);
        $this->assertStringNotContainsString('APP_KEY', $spec);
        $this->assertDoesNotMatchRegularExpression('/^(workers|jobs|databases|domains):/m', $spec);
        $this->assertFileDoesNotExist(base_path('Dockerfile'));

        $build = file_get_contents(base_path('scripts/prepare-production.sh'));
        $this->assertIsString($build);
        $this->assertStringContainsString('composer install --no-dev', $build);
        $this->assertStringContainsString('npm ci', $build);
        $this->assertStringContainsString('npm run build', $build);
        $this->assertStringContainsString('npm prune --omit=dev', $build);
        $this->assertStringContainsString('rm -rf node_modules', $build);
        $this->assertStringContainsString('chmod 0444 database/demo.sqlite', $build);
    }

    public function test_demo_database_build_is_reproducible_and_has_the_exact_manifest(): void
    {
        $path = database_path('production-test-'.bin2hex(random_bytes(4)).'.sqlite');

        try {
            $first = $this->buildDatabase($path);
            $second = $this->buildDatabase($path);

            $this->assertSame($first, $second);
            $this->assertSame(8, $second['counts']['leads']);
            $this->assertSame(68, $second['counts']['events']);
            $this->assertSame(0, $second['counts']['jobs']);
        } finally {
            @unlink($path);
        }
    }

    public function test_production_runtime_is_read_only_and_honours_the_https_proxy(): void
    {
        $path = database_path('production-runtime-test-'.bin2hex(random_bytes(4)).'.sqlite');

        try {
            $this->buildDatabase($path);
            $environment = array_merge($this->productionEnvironment($path), [
                'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
                'DB_READ_ONLY' => 'true',
                'TRUSTED_PROXIES' => '*',
            ]);
            $process = new Process([
                PHP_BINARY,
                '-r',
                <<<'PHP'
                    require 'vendor/autoload.php';
                    $app = require 'bootstrap/app.php';
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    $database = $app->make('db');

                    echo $database->table('leads')->count(), PHP_EOL;
                    try {
                        $database->table('leads')->limit(1)->update(['company' => 'forbidden']);
                        echo 'write-allowed', PHP_EOL;
                    } catch (Illuminate\Database\QueryException) {
                        echo 'write-blocked', PHP_EOL;
                    }

                    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
                    $health = Illuminate\Http\Request::create('/health', 'GET', server: [
                        'REMOTE_ADDR' => '10.0.0.10',
                        'HTTP_X_FORWARDED_PROTO' => 'https',
                        'HTTP_X_FORWARDED_HOST' => 'leadops-demo.ondigitalocean.app',
                        'HTTP_X_FORWARDED_PORT' => '443',
                    ]);
                    $healthResponse = $kernel->handle($health);
                    echo $healthResponse->getStatusCode(), PHP_EOL;
                    echo $healthResponse->getContent() === '' ? 'empty' : 'not-empty', PHP_EOL;
                    echo $health->isSecure() ? 'secure' : 'insecure', PHP_EOL;
                    $kernel->terminate($health, $healthResponse);

                    $mutation = Illuminate\Http\Request::create('/leads', 'POST');
                    $mutationResponse = $kernel->handle($mutation);
                    echo $mutationResponse->getStatusCode(), PHP_EOL;
                    $kernel->terminate($mutation, $mutationResponse);

                    echo config('session.driver'), PHP_EOL;
                    echo config('cache.default'), PHP_EOL;
                    echo config('queue.default'), PHP_EOL;
                    echo get_class($app->make('queue')->connection()), PHP_EOL;
                    echo config('logging.default'), PHP_EOL;
                    PHP,
            ], base_path(), $environment);
            $process->mustRun();

            $this->assertSame([
                '8',
                'write-blocked',
                '204',
                'empty',
                'secure',
                '404',
                'array',
                'array',
                'discard',
                'Illuminate\Queue\NullQueue',
                'stderr',
            ], preg_split('/\R/', trim($process->getOutput())));
        } finally {
            @unlink($path);
        }
    }

    /** @return array{counts: array{leads: int, events: int, jobs: int}, snapshot: string} */
    private function buildDatabase(string $path): array
    {
        $relativePath = 'database/'.basename($path);
        $process = new Process([
            PHP_BINARY,
            'artisan',
            'leadops:build-demo-database',
            $relativePath,
            '--no-interaction',
        ], base_path(), $this->productionEnvironment($path));
        $process->mustRun();

        $pdo = new PDO('sqlite:'.$path);
        $counts = [
            'leads' => (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn(),
            'events' => (int) $pdo->query('SELECT COUNT(*) FROM processing_events')->fetchColumn(),
            'jobs' => (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),
        ];
        $snapshot = json_encode([
            'leads' => $pdo->query('SELECT * FROM leads ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'events' => $pdo->query('SELECT * FROM processing_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'jobs' => $pdo->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_THROW_ON_ERROR);

        return ['counts' => $counts, 'snapshot' => hash('sha256', $snapshot)];
    }

    /** @return array<string, string> */
    private function productionEnvironment(string $path): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $path,
            'DB_READ_ONLY' => 'false',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'discard',
            'QUEUE_FAILED_DRIVER' => 'null',
            'LOG_CHANNEL' => 'stderr',
        ];
    }
}
