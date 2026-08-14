<?php

namespace App\Console\Commands;

use Database\Seeders\LeadDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BuildDemoDatabase extends Command
{
    protected $signature = 'leadops:build-demo-database
        {path=database/demo.sqlite : SQLite file to rebuild inside the database directory}';

    protected $description = 'Rebuild the deterministic read-only LeadOps demonstration database';

    public function handle(): int
    {
        $path = $this->validatedPath((string) $this->argument('path'));
        $originalDefaultConnection = config('database.default');
        $originalDatabase = config('database.connections.sqlite.database');
        $originalPragmas = config('database.connections.sqlite.pragmas');

        try {
            DB::purge('sqlite');

            if (File::exists($path) && ! @chmod($path, 0644)) {
                throw new RuntimeException('The existing demo database could not be made writable.');
            }

            if (File::exists($path) && ! File::delete($path)) {
                throw new RuntimeException('The existing demo database could not be removed.');
            }

            File::put($path, '');

            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $path,
                'database.connections.sqlite.pragmas' => [],
            ]);

            $this->callSilently('migrate:fresh', [
                '--database' => 'sqlite',
                '--force' => true,
            ]);
            $this->callSilently('db:seed', [
                '--class' => LeadDemoSeeder::class,
                '--database' => 'sqlite',
                '--force' => true,
            ]);

            $counts = [
                'leads' => DB::connection('sqlite')->table('leads')->count(),
                'events' => DB::connection('sqlite')->table('processing_events')->count(),
                'jobs' => DB::connection('sqlite')->table('jobs')->count(),
            ];

            if ($counts !== ['leads' => 8, 'events' => 68, 'jobs' => 0]) {
                throw new RuntimeException(sprintf(
                    'Unexpected demo database counts: %d leads, %d events, %d jobs.',
                    $counts['leads'],
                    $counts['events'],
                    $counts['jobs'],
                ));
            }

            $this->components->info(sprintf(
                'Demo database ready: %d leads, %d events, %d jobs.',
                $counts['leads'],
                $counts['events'],
                $counts['jobs'],
            ));

            return self::SUCCESS;
        } finally {
            DB::purge('sqlite');
            config([
                'database.default' => $originalDefaultConnection,
                'database.connections.sqlite.database' => $originalDatabase,
                'database.connections.sqlite.pragmas' => $originalPragmas,
            ]);
        }
    }

    private function validatedPath(string $path): string
    {
        $absolutePath = $this->isAbsolutePath($path) ? $path : base_path($path);
        $databaseDirectory = realpath(database_path());
        $targetDirectory = realpath(dirname($absolutePath));

        if ($databaseDirectory === false
            || $targetDirectory === false
            || strcasecmp($databaseDirectory, $targetDirectory) !== 0
            || strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'sqlite') {
            throw new RuntimeException('The demo database must be a .sqlite file directly inside database/.');
        }

        return $targetDirectory.DIRECTORY_SEPARATOR.basename($absolutePath);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
