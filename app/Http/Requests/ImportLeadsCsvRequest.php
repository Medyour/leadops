<?php

namespace App\Http\Requests;

use App\Support\LocalEnvironment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class ImportLeadsCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return LocalEnvironment::allowsMutations();
    }

    /** @return array<string, list<string|int>> */
    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'max:'.(int) ceil(((int) config('leadops.csv_import.max_bytes')) / 1024),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile
                        && $value->getSize() > (int) config('leadops.csv_import.max_bytes')) {
                        $fail('Le fichier CSV ne doit pas dépasser 2 Mio.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'csv_file.required' => 'Sélectionnez un fichier CSV à importer.',
            'csv_file.file' => 'Le fichier transmis est invalide.',
            'csv_file.max' => 'Le fichier CSV ne doit pas dépasser 2 Mio.',
        ];
    }
}
