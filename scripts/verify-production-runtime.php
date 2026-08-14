<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\NullQueue;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$ensure = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$database = $app->make('db');
$counts = [
    'leads' => $database->table('leads')->count(),
    'events' => $database->table('processing_events')->count(),
    'jobs' => $database->table('jobs')->count(),
];

$ensure($counts === ['leads' => 8, 'events' => 68, 'jobs' => 0], 'Unexpected production database manifest.');
$ensure(is_readable(database_path('demo.sqlite')), 'The production database is not readable.');
$ensure((int) $database->selectOne('PRAGMA query_only')->query_only === 1, 'SQLite query-only mode is disabled.');
$ensure(strtolower((string) $database->selectOne('PRAGMA journal_mode')->journal_mode) === 'delete', 'Unexpected SQLite journal mode.');

foreach (['-wal', '-shm', '-journal'] as $suffix) {
    $ensure(! file_exists(database_path('demo.sqlite'.$suffix)), 'A residual SQLite journal exists.');
}

$writeBlocked = false;

try {
    $database->table('leads')->limit(1)->update(['company' => 'forbidden']);
} catch (QueryException) {
    $writeBlocked = true;
}

$ensure($writeBlocked, 'A production database write unexpectedly succeeded.');

$kernel = $app->make(HttpKernel::class);
$probe = static function (string $path, string $method = 'GET') use ($kernel): array {
    $request = Request::create($path, $method, server: [
        'HTTP_HOST' => 'leadops-ci.example.test',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_HOST' => 'attacker.example.test',
        'HTTP_X_FORWARDED_PORT' => '81',
    ]);
    $response = $kernel->handle($request);
    $result = [
        'status' => $response->getStatusCode(),
        'body' => $response->getContent(),
        'set_cookie' => $response->headers->has('Set-Cookie'),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
    ];
    $kernel->terminate($request, $response);

    return $result;
};

$health = $probe('/health');
$ensure($health === [
    'status' => 204,
    'body' => '',
    'set_cookie' => false,
    'secure' => true,
    'host' => 'leadops-ci.example.test',
], 'The health check or trusted proxy invariant failed.');

$public = [];

foreach (['/', '/leads', '/exceptions', '/leads/1'] as $path) {
    $response = $probe($path);
    $ensure($response['status'] === 200, 'A public GET route failed.');
    $ensure(! str_contains($response['body'], 'attacker.example.test'), 'A forwarded host reached a public response.');
    $ensure(! str_contains($response['body'], 'http://leadops-ci.example.test'), 'A public response contains an HTTP application URL.');
    $public[$path] = $response['status'];
}

$mutations = [
    'POST' => $probe('/leads', 'POST')['status'],
    'PUT' => $probe('/leads', 'PUT')['status'],
    'PATCH' => $probe('/leads', 'PATCH')['status'],
    'DELETE' => $probe('/leads', 'DELETE')['status'],
];

$ensure($mutations === ['POST' => 404, 'PUT' => 405, 'PATCH' => 405, 'DELETE' => 405], 'A production mutation is not closed.');
$ensure($app->configurationIsCached(), 'The production configuration is not cached.');
$ensure(config('session.driver') === 'array', 'The session driver requires persistence.');
$ensure(config('cache.default') === 'array', 'The cache store requires persistence.');
$ensure(config('queue.default') === 'discard', 'The queue connection requires persistence.');
$ensure(get_class($app->make('queue')->connection()) === NullQueue::class, 'The queue does not discard jobs.');
$ensure(config('logging.default') === 'stderr', 'Logs are not sent to stderr.');

echo json_encode([
    'cached' => true,
    'counts' => $counts,
    'health' => $health['status'],
    'public_gets' => $public,
    'mutations' => $mutations,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
