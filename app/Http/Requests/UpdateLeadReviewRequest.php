<?php

namespace App\Http\Requests;

use App\Support\LocalEnvironment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateLeadReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return LocalEnvironment::allowsMutations();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50', 'regex:/^\+?[0-9 .()\-]{7,50}$/'],
            'country' => ['nullable', 'string', 'size:2', 'alpha:ascii'],
            'service_interest' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'company_size' => ['nullable', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent' => ['required', 'boolean'],
            'source' => ['prohibited'],
            'external_reference' => ['prohibited'],
            'received_at' => ['prohibited'],
            'status' => ['prohibited'],
            'score' => ['prohibited'],
            'classification' => ['prohibited'],
            'assigned_to' => ['prohibited'],
            'duplicate_of' => ['prohibited'],
            'processing_attempts' => ['prohibited'],
            'processed_at' => ['prohibited'],
            'normalized_email' => ['prohibited'],
            'normalized_phone' => ['prohibited'],
            'cycle_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required_without' => 'Renseignez une adresse e-mail ou un téléphone.',
            'phone.required_without' => 'Renseignez un téléphone ou une adresse e-mail.',
            'phone.regex' => 'Le format du téléphone est invalide.',
            '*.prohibited' => 'Ce champ ne peut pas être modifié.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            $this->redirector
                ->to($this->getRedirectUrl())
                ->withErrors($validator, $this->errorBag),
        );
    }
}
