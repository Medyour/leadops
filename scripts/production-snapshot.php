<?php

declare(strict_types=1);

$path = $argv[1] ?? 'database/demo.sqlite';
$absolutePath = realpath($path);
$databaseDirectory = realpath(__DIR__.'/../database');

if ($absolutePath === false
    || $databaseDirectory === false
    || strcasecmp(dirname($absolutePath), $databaseDirectory) !== 0) {
    fwrite(STDERR, "Expected an existing SQLite file directly inside database/.\n");
    exit(1);
}

$pdo = new PDO('sqlite:'.$absolutePath);
$data = [
    'leads' => $pdo->query('SELECT * FROM leads ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'events' => $pdo->query('SELECT * FROM processing_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'jobs' => $pdo->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
];

echo json_encode([
    'counts' => [
        'leads' => count($data['leads']),
        'events' => count($data['events']),
        'jobs' => count($data['jobs']),
    ],
    'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
