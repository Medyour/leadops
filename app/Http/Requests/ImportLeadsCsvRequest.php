<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportLeadsCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** @return array<string, list<string|int>> */
    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'max:'.(int) ceil(((int) config('leadops.csv_import.max_bytes')) / 1024),
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
