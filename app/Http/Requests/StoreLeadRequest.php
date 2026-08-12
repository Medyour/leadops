<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source' => ['prohibited'],
            'external_reference' => ['required', 'uuid'],
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'external_reference.required' => 'La référence du formulaire est absente. Ouvrez un nouveau formulaire.',
            'external_reference.uuid' => 'La référence du formulaire est invalide. Ouvrez un nouveau formulaire.',
            'email.required_without' => 'Renseignez une adresse e-mail ou un téléphone.',
            'phone.required_without' => 'Renseignez un téléphone ou une adresse e-mail.',
            'phone.regex' => 'Le format du téléphone est invalide.',
            'consent.accepted' => 'Le consentement explicite est requis.',
        ];
    }
}
