<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Http\Requests\ImportLeadsCsvRequest;
use App\Services\Imports\CsvImportParser;
use App\Services\Imports\InvalidCsvFile;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class CsvImportController extends Controller
{
    public function create(): View
    {
        return view('imports.csv.create', [
            'header' => CsvImportParser::HEADER,
            'summary' => session('csv_import_summary'),
        ]);
    }

    public function store(
        ImportLeadsCsvRequest $request,
        CsvImportParser $parser,
        LeadIngestionService $ingestion,
    ): RedirectResponse {
        $uploadedFile = $request->file('csv_file');
        $contents = file_get_contents($uploadedFile->getRealPath());

        if ($contents === false) {
            return back()->withErrors(['csv_file' => 'Le fichier transmis ne peut pas être lu.']);
        }

        try {
            $rows = $parser->parse($contents);
        } catch (InvalidCsvFile $exception) {
            return back()->withErrors(['csv_file' => $exception->errors]);
        } finally {
            unset($contents);
        }

        $summary = [
            'created' => 0,
            'already_received' => 0,
            'invalid' => 0,
            'results' => [],
        ];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $reference = trim($row['external_reference']);
            $validator = Validator::make($row, $this->rowRules());

            if ($validator->fails()) {
                $summary['invalid']++;
                $summary['results'][] = $this->lineResult(
                    $line,
                    'invalid',
                    array_keys($validator->errors()->messages()),
                    $reference,
                );

                continue;
            }

            $validated = $validator->validated();
            unset($validated['external_reference']);
            $validated = array_map(
                static fn (mixed $value): mixed => $value === '' ? null : $value,
                $validated,
            );

            try {
                $result = $ingestion->ingest(LeadSource::Csv, $reference, $validated);
                $status = $result->created ? 'created' : 'already_received';
                $summary[$status]++;
                $summary['results'][] = $this->lineResult($line, $status, [], $reference);
            } catch (Throwable $exception) {
                Log::warning('CSV lead row ingestion failed.', [
                    'line' => $line,
                    'exception_class' => $exception::class,
                ]);
                $summary['invalid']++;
                $summary['results'][] = $this->lineResult($line, 'invalid', ['row'], $reference);
            }
        }

        return to_route('imports.csv.create')->with('csv_import_summary', $summary);
    }

    /** @return array<string, list<string>> */
    private function rowRules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50', 'regex:/^\+?[0-9 .()\-]{7,50}$/'],
            'country' => ['nullable', 'string', 'size:2', 'alpha:ascii'],
            'service_interest' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'company_size' => ['nullable', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent' => ['required', 'accepted'],
            'received_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }

    /** @return array{line: int, status: string, fields: list<string>, external_reference: string|null} */
    private function lineResult(int $line, string $status, array $fields, string $reference): array
    {
        return [
            'line' => $line,
            'status' => $status,
            'fields' => array_values($fields),
            'external_reference' => $reference !== '' ? $reference : null,
        ];
    }
}
