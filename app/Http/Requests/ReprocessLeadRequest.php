<?php

namespace App\Http\Requests;

use App\Support\LocalEnvironment;
use Illuminate\Foundation\Http\FormRequest;

class ReprocessLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return LocalEnvironment::allowsMutations();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cycle_id' => ['prohibited'],
        ];
    }
}
