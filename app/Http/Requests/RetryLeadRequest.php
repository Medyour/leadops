<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetryLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
