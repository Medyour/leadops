<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprocessLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cycle_id' => ['prohibited'],
        ];
    }
}
