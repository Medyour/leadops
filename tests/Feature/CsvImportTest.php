<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Services\Imports\CsvImportParser;
use App\Services\Imports\InvalidCsvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(static fn (): string => 'testing');
        RateLimiter::clear('csv-import:test');
    }

    public function test_routes_and_accessible_form_expose_the_strict_contract_and_example(): void
    {
        $create = app('router')->getRoutes()->getByName('imports.csv.create');
        $store = app('router')->getRoutes()->getByName('imports.csv.store');

        $this->assertSame('imports/csv', $create->uri());
        $this->assertSame(['GET', 'HEAD'], $create->methods());
        $this->assertSame('imports/csv', $store->uri());
        $this->assertSame(['POST'], $store->methods());
        $this->assertContains('throttle:csv-import', $store->gatherMiddleware());

        $this->get(route('imports.csv.create'))
            ->assertOk()
            ->assertSee('<form method="POST" action="'.route('imports.csv.store').'" enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false)
            ->assertSee('name="csv_file"', false)
            ->assertSee('required aria-describedby="csv-file-help"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('Lancer l’import')
            ->assertSee('data-csv-import-preview data-input-id="csv_file"', false)
            ->assertSee('Laravel est l’unique autorité de validation')
            ->assertSee(implode(',', CsvImportParser::HEADER))
            ->assertSee('leads-import-example.csv')
            ->assertSee('UTF-8')
            ->assertSee('500 lignes');

        $example = file_get_contents(public_path('examples/leads-import-example.csv'));
        $this->assertIsString($example);
        $this->assertStringStartsWith(implode(',', CsvImportParser::HEADER)."\n", $example);
        $this->assertStringContainsString('@example.test', $example);
        $this->assertStringNotContainsString('@gmail.com', $example);

        $rows = app(CsvImportParser::class)->parse($example);
        $this->assertCount(2, $rows);
        $this->assertSame('csv-example-001', $rows[0]['external_reference']);

        Queue::fake();
        $this->postCsv($example, 'leads-import-example.csv')
            ->assertSessionHas('csv_import_summary.created', 2)
            ->assertSessionHas('csv_import_summary.invalid', 0);
    }

    public function test_valid_file_creates_leads_events_and_one_after_commit_job_per_new_line(): void
    {
        Queue::fake();

        $response = $this->postCsv($this->csv([
            $this->row('csv-valid-001'),
            $this->row('csv-valid-002', ['email' => 'second@example.test']),
        ]));

        $response->assertRedirect(route('imports.csv.create'))
            ->assertSessionHas('csv_import_summary.created', 2)
            ->assertSessionHas('csv_import_summary.already_received', 0)
            ->assertSessionHas('csv_import_summary.invalid', 0);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('processing_events', 2);
        Queue::assertPushed(ProcessLead::class, 2);
        Queue::assertPushed(ProcessLead::class, fn (ProcessLead $job): bool => $job->afterCommit);
    }

    public function test_mixed_created_repeated_and_invalid_rows_are_reported_independently(): void
    {
        Queue::fake();
        $existing = Lead::factory()->create([
            'source' => LeadSource::Csv,
            'external_reference' => 'csv-existing-001',
        ]);

        $response = $this->postCsv($this->csv([
            $this->row('csv-created-001'),
            $this->row($existing->external_reference, ['email' => 'changed@example.test']),
            $this->row('csv-invalid-001', ['first_name' => '', 'email' => '', 'phone' => '']),
        ]));

        $response->assertSessionHas('csv_import_summary.created', 1)
            ->assertSessionHas('csv_import_summary.already_received', 1)
            ->assertSessionHas('csv_import_summary.invalid', 1);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('processing_events', 1);
        Queue::assertPushed(ProcessLead::class, 1);

        $summary = session('csv_import_summary');
        $this->assertSame(['created', 'already_received', 'invalid'], array_column($summary['results'], 'status'));
        $this->assertContains('first_name', $summary['results'][2]['fields']);
        $this->assertContains('email', $summary['results'][2]['fields']);
        $this->assertContains('phone', $summary['results'][2]['fields']);
    }

    public function test_repeating_an_import_is_idempotent_without_new_events_or_jobs(): void
    {
        Queue::fake();
        $csv = $this->csv([$this->row('csv-repeat-001')]);

        $this->postCsv($csv)->assertSessionHas('csv_import_summary.created', 1);
        $this->postCsv($csv)->assertSessionHas('csv_import_summary.already_received', 1);

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_duplicate_references_inside_the_same_file_create_one_lead_event_and_job(): void
    {
        Queue::fake();

        $response = $this->postCsv($this->csv([
            $this->row('csv-same-file-001'),
            $this->row('csv-same-file-001', [
                'first_name' => 'Valeur ignorée',
                'email' => 'ignored@example.test',
            ]),
        ]));

        $response->assertSessionHas('csv_import_summary.created', 1)
            ->assertSessionHas('csv_import_summary.already_received', 1)
            ->assertSessionHas('csv_import_summary.invalid', 0);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        $this->assertSame('Aline', Lead::query()->sole()->first_name);
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_csv_job_is_inserted_only_after_the_outer_database_commit(): void
    {
        DB::beginTransaction();

        try {
            $this->postCsv($this->csv([$this->row('csv-after-commit-001')]))
                ->assertSessionHas('csv_import_summary.created', 1);

            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseCount('processing_events', 1);
            $this->assertDatabaseCount('jobs', 0);

            DB::commit();

            $this->assertDatabaseCount('jobs', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_a_transactional_failure_on_one_row_does_not_roll_back_later_rows(): void
    {
        Queue::fake();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_selected_csv_receipt
            BEFORE INSERT ON processing_events
            WHEN (SELECT external_reference FROM leads WHERE id = NEW.lead_id) = 'csv-fail-001'
            BEGIN
                SELECT RAISE(ABORT, 'simulated safe row failure');
            END
            SQL);

        try {
            $response = $this->postCsv($this->csv([
                $this->row('csv-fail-001'),
                $this->row('csv-survives-001', ['email' => 'survives@example.test']),
            ]));

            $response->assertSessionHas('csv_import_summary.created', 1)
                ->assertSessionHas('csv_import_summary.invalid', 1);
            $this->assertDatabaseMissing('leads', ['external_reference' => 'csv-fail-001']);
            $this->assertDatabaseHas('leads', ['external_reference' => 'csv-survives-001']);
            $this->assertDatabaseCount('processing_events', 1);
            Queue::assertPushed(ProcessLead::class, 1);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_selected_csv_receipt');
        }
    }

    public function test_bom_and_quoted_commas_escaped_quotes_and_newlines_are_supported(): void
    {
        Queue::fake();
        $csv = "\xEF\xBB\xBF".$this->csv([
            $this->row('csv-quoted-001', [
                'company' => 'Atelier, Exemple',
                'message' => "Première ligne \"fictive\".\nSeconde ligne, toujours fictive.",
            ]),
        ]);

        $this->postCsv($csv)->assertSessionHas('csv_import_summary.created', 1);
        $lead = Lead::query()->sole();
        $this->assertSame('Atelier, Exemple', $lead->company);
        $this->assertSame("Première ligne \"fictive\".\nSeconde ligne, toujours fictive.", $lead->message);
    }

    public function test_bom_is_rejected_everywhere_except_the_first_bytes_before_any_write(): void
    {
        $valid = $this->csv([$this->row('csv-before-late-bom-001')]);
        $cases = [
            "\xEF\xBB\xBF\xEF\xBB\xBF".$valid,
            $valid."\n"."\xEF\xBB\xBF".implode(',', array_values($this->row('csv-late-bom-001'))),
            str_replace('Atelier Fictif', "Atelier \xEF\xBB\xBF Fictif", $valid),
        ];

        foreach ($cases as $offset => $contents) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($offset + 1)]);
            $this->postCsv($contents, "bom-{$offset}.csv")
                ->assertSessionHasErrors('csv_file');
        }

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_strict_headers_reject_missing_duplicate_unexpected_and_wrong_order_before_writes(): void
    {
        $cases = [
            'missing' => array_slice(CsvImportParser::HEADER, 0, -1),
            'duplicate' => [...array_slice(CsvImportParser::HEADER, 0, -1), 'consent'],
            'unexpected' => [...array_slice(CsvImportParser::HEADER, 0, -1), 'secret_payload'],
            'order' => [CsvImportParser::HEADER[1], CsvImportParser::HEADER[0], ...array_slice(CsvImportParser::HEADER, 2)],
        ];

        $offset = 0;
        foreach ($cases as $name => $header) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(++$offset)]);
            $response = $this->postCsv(implode(',', $header)."\n", "headers-{$name}.csv");
            $response->assertSessionHasErrors('csv_file');
        }

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
    }

    public function test_wrong_column_count_and_more_than_500_rows_fail_before_any_write(): void
    {
        $wrongColumns = implode(',', CsvImportParser::HEADER)."\nonly,three,columns\n";
        $this->postCsv($wrongColumns)->assertSessionHasErrors('csv_file');

        $rows = [];
        for ($index = 1; $index <= 501; $index++) {
            $rows[] = $this->row(sprintf('csv-limit-%03d', $index), [
                'email' => sprintf('limit-%03d@example.test', $index),
            ]);
        }

        $this->postCsv($this->csv($rows))->assertSessionHasErrors('csv_file');
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_late_structural_errors_are_detected_before_any_valid_preceding_row_is_written(): void
    {
        $validPrefix = $this->csv([$this->row('csv-must-not-write-001')]);
        $cases = [
            $validPrefix."\nonly,three,columns",
            $validPrefix."\n\"unterminated",
            $validPrefix."\n".implode(',', array_values($this->row('csv-late-quote-001', [
                'message' => 'Texte "mal cité',
            ]))),
        ];

        foreach ($cases as $offset => $contents) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.18.0.'.($offset + 1)]);
            $this->postCsv($contents, "late-structure-{$offset}.csv")
                ->assertSessionHasErrors('csv_file');
        }

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_final_newline_is_ignored_but_an_additional_blank_record_is_rejected_deterministically(): void
    {
        $parser = app(CsvImportParser::class);
        $contents = $this->csv([$this->row('csv-final-newline-001')]);

        $this->assertCount(1, $parser->parse($contents));
        $this->assertCount(1, $parser->parse($contents."\n"));

        try {
            $parser->parse($contents."\n\n");
            $this->fail('An additional blank CSV record must be rejected.');
        } catch (InvalidCsvFile $exception) {
            $this->assertStringContainsString('exactement 12 colonnes', implode(' ', $exception->errors));
        }

        Queue::fake();
        $emptyRecord = implode(',', array_fill(0, count(CsvImportParser::HEADER), ''));
        $this->postCsv($this->csv([])."\n".$emptyRecord)
            ->assertSessionHas('csv_import_summary.created', 0)
            ->assertSessionHas('csv_import_summary.invalid', 1);
        $this->assertDatabaseCount('leads', 0);
        Queue::assertNothingPushed();
    }

    public function test_exactly_500_rows_are_accepted(): void
    {
        Queue::fake();
        $rows = [];

        for ($index = 1; $index <= 500; $index++) {
            $rows[] = $this->row(sprintf('csv-max-%03d', $index), [
                'email' => sprintf('max-%03d@example.test', $index),
            ]);
        }

        $this->postCsv($this->csv($rows))
            ->assertSessionHas('csv_import_summary.created', 500)
            ->assertSessionHas('csv_import_summary.invalid', 0);
        $this->assertDatabaseCount('leads', 500);
        Queue::assertPushed(ProcessLead::class, 500);
    }

    public function test_empty_binary_invalid_utf8_and_malformed_csv_are_rejected(): void
    {
        $files = [
            'empty.csv' => '',
            'binary.csv' => implode(',', CsvImportParser::HEADER)."\nabc\0def",
            'utf8.csv' => implode(',', CsvImportParser::HEADER)."\n\xC3\x28",
            'malformed.csv' => implode(',', CsvImportParser::HEADER)."\n\"unterminated",
        ];

        $offset = 0;
        foreach ($files as $name => $contents) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(++$offset)]);
            $this->postCsv($contents, $name)->assertSessionHasErrors('csv_file');
        }

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_two_mibibyte_limit_is_enforced_using_actual_content_not_extension_or_mime(): void
    {
        Queue::fake();
        $header = implode(',', CsvImportParser::HEADER)."\n";
        $prefix = 'csv-size-001,Aline,Exemple,Atelier,size@example.test,,FR,automation,4,"';
        $suffix = '",1,2026-08-12T10:00:00+00:00';
        $maximum = (int) config('leadops.csv_import.max_bytes');
        $atLimit = $header.$prefix.str_repeat('a', $maximum - strlen($header.$prefix.$suffix)).$suffix;

        $this->postCsv($atLimit, 'not-really.csv.txt', 'application/octet-stream')
            ->assertSessionHas('csv_import_summary.invalid', 1)
            ->assertSessionDoesntHaveErrors('csv_file');

        $this->postCsv($atLimit.'x', 'oversize.csv')
            ->assertSessionHasErrors('csv_file');
    }

    public function test_csv_source_is_forced_and_user_content_cannot_override_it(): void
    {
        Queue::fake();

        $this->postCsv($this->csv([$this->row('csv-source-001')]))
            ->assertSessionHas('csv_import_summary.created', 1);

        $this->assertSame(LeadSource::Csv, Lead::query()->sole()->source);
        $this->assertSame(CsvImportParser::HEADER, array_values(array_filter(
            CsvImportParser::HEADER,
            static fn (string $field): bool => $field !== 'source',
        )));
    }

    public function test_summary_never_leaks_sensitive_row_values(): void
    {
        Queue::fake();
        $email = 'never-display-this@example.test';
        $phone = '+12025550987';
        $message = 'Message sensible fictif qui ne doit jamais apparaître dans le résumé.';

        $this->postCsv($this->csv([
            $this->row('csv-private-001', [
                'first_name' => '',
                'email' => $email,
                'phone' => $phone,
                'message' => $message,
            ]),
        ]));

        $response = $this->get(route('imports.csv.create'));
        $response->assertOk()
            ->assertSee('csv-private-001')
            ->assertSee('first_name')
            ->assertDontSee($email)
            ->assertDontSee($phone)
            ->assertDontSee($message);
    }

    public function test_references_and_error_labels_are_html_escaped_in_the_summary(): void
    {
        Queue::fake();
        $reference = '<script>alert("reference")</script>';

        $this->postCsv($this->csv([
            $this->row($reference, ['first_name' => '']),
        ]));

        $this->get(route('imports.csv.create'))
            ->assertOk()
            ->assertSee(e($reference), false)
            ->assertDontSee($reference, false)
            ->assertSee('first_name');
    }

    public function test_row_failures_do_not_log_exception_messages_or_sensitive_csv_values(): void
    {
        Queue::fake();
        Log::spy();
        $email = 'private-log@example.test';
        $phone = '+12025550986';
        $message = 'private-message-marker';

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_csv_log_safety
            BEFORE INSERT ON processing_events
            WHEN (SELECT external_reference FROM leads WHERE id = NEW.lead_id) = 'csv-log-safety-001'
            BEGIN
                SELECT RAISE(ABORT, 'private-exception-marker');
            END
            SQL);

        try {
            $this->postCsv($this->csv([
                $this->row('csv-log-safety-001', [
                    'email' => $email,
                    'phone' => $phone,
                    'message' => $message,
                ]),
            ]))->assertSessionHas('csv_import_summary.invalid', 1);

            Log::shouldHaveReceived('warning')->once()->withArgs(
                function (string $logMessage, array $context) use ($email, $phone, $message): bool {
                    $serialized = $logMessage.' '.json_encode($context);

                    return $logMessage === 'CSV lead row ingestion failed.'
                        && $context === ['line' => 2]
                        && ! str_contains($serialized, $email)
                        && ! str_contains($serialized, $phone)
                        && ! str_contains($serialized, $message)
                        && ! str_contains($serialized, 'private-exception-marker');
                },
            );

            $response = $this->get(route('imports.csv.create'));
            foreach ([$email, $phone, $message, 'private-exception-marker'] as $sensitiveValue) {
                $response->assertDontSee($sensitiveValue);
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_csv_log_safety');
        }
    }

    public function test_structural_errors_do_not_echo_an_unexpected_sensitive_header(): void
    {
        $sensitiveHeader = 'private-person@example.test';
        $header = [...array_slice(CsvImportParser::HEADER, 0, -1), $sensitiveHeader];

        $response = $this->postCsv(implode(',', $header)."\n");
        $response->assertSessionHasErrors('csv_file');

        $errors = session('errors')->getBag('default')->all();
        $this->assertStringContainsString('en-têtes inattendus', implode(' ', $errors));
        $this->assertStringNotContainsString($sensitiveHeader, implode(' ', $errors));
    }

    public function test_file_errors_are_associated_with_the_upload_control_accessibly(): void
    {
        $this->from(route('imports.csv.create'));
        $this->followingRedirects();

        $this->postCsv('invalid-header')
            ->assertOk()
            ->assertSee('id="csv-file-errors"', false)
            ->assertSee('aria-describedby="csv-file-help csv-file-errors"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_fourth_import_from_the_same_client_is_rate_limited(): void
    {
        config()->set('leadops.csv_import.rate_limit_per_minute', 3);
        $csv = $this->csv([]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postCsv($csv)->assertRedirect(route('imports.csv.create'));
        }

        $this->postCsv($csv)->assertTooManyRequests();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_csrf_rate_limit_environment_restriction_and_hmac_key_are_enforced(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        $this->withoutExceptionHandling();

        try {
            $this->post('/imports/csv', [
                'csv_file' => UploadedFile::fake()->createWithContent('leads.csv', $this->csv([])),
            ]);
            $this->fail('A missing CSRF token must be rejected.');
        } catch (TokenMismatchException) {
            $this->assertDatabaseCount('leads', 0);
        }

        $ip = '192.0.2.99';
        $request = Request::create('/imports/csv', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $limit = RateLimiter::limiter('csv-import')($request);
        $this->assertStringNotContainsString($ip, $limit->key);
        $this->assertMatchesRegularExpression('/^csv-import:[a-f0-9]{64}$/', $limit->key);
        $this->assertSame(3, $limit->maxAttempts);

        $this->withExceptionHandling();
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->get('/imports/csv')->assertNotFound();
        $this->postCsv($this->csv([$this->row('csv-production-001')]))->assertNotFound();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_uploaded_file_is_never_copied_to_application_storage(): void
    {
        Queue::fake();
        $before = $this->applicationStorageFiles();

        $this->postCsv($this->csv([$this->row('csv-no-storage-001')]))
            ->assertSessionHas('csv_import_summary.created', 1);

        $this->assertSame($before, $this->applicationStorageFiles());
    }

    /** @param list<array<string, string>> $rows */
    private function csv(array $rows): string
    {
        $records = [CsvImportParser::HEADER, ...array_map(
            static fn (array $row): array => array_map(static fn (string $field): string => $row[$field], CsvImportParser::HEADER),
            $rows,
        )];

        return implode("\n", array_map(function (array $record): string {
            return implode(',', array_map([$this, 'quoteCsv'], $record));
        }, $records));
    }

    /** @param array<string, string> $overrides @return array<string, string> */
    private function row(string $reference, array $overrides = []): array
    {
        return [
            ...array_combine(CsvImportParser::HEADER, array_fill(0, count(CsvImportParser::HEADER), '')),
            'external_reference' => $reference,
            'first_name' => 'Aline',
            'last_name' => 'Exemple',
            'company' => 'Atelier Fictif',
            'email' => 'aline@example.test',
            'phone' => '+12025550123',
            'country' => 'FR',
            'service_interest' => 'automation',
            'company_size' => '12',
            'message' => 'Message entièrement fictif pour le test CSV.',
            'consent' => '1',
            'received_at' => '2026-08-12T10:00:00+00:00',
            ...$overrides,
        ];
    }

    private function quoteCsv(string $value): string
    {
        if (! str_contains($value, ',') && ! str_contains($value, '"') && ! str_contains($value, "\n")) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private function postCsv(string $contents, string $name = 'leads.csv', string $mime = 'text/csv')
    {
        return $this->post(route('imports.csv.store'), [
            'csv_file' => UploadedFile::fake()->createWithContent($name, $contents, $mime),
        ]);
    }

    /** @return array<string, string|false> */
    private function applicationStorageFiles(): array
    {
        return collect(File::allFiles(storage_path('app')))
            ->mapWithKeys(static fn ($file): array => [
                $file->getRelativePathname() => hash_file('sha256', $file->getRealPath()),
            ])
            ->sortKeys()
            ->all();
    }
}
