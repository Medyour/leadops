<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreLocalWebhookLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();

        if (! $this->isJson() || ! json_validate($content)) {
            return ['_envelope' => null];
        }

        $envelope = json_decode($content, true);

        if (! is_array($envelope)) {
            return ['_envelope' => $envelope];
        }

        return [
            '_envelope' => $envelope,
            ...$envelope,
        ];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            '_envelope' => ['required', 'array:lead'],
            'lead' => ['required', 'array:source,external_reference,first_name,last_name,company,email,phone,country,service_interest,company_size,message,consent,received_at'],
            'lead.source' => ['sometimes', 'string', 'max:20'],
            'lead.external_reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/'],
            'lead.first_name' => ['required', 'string', 'max:100'],
            'lead.last_name' => ['required', 'string', 'max:100'],
            'lead.company' => ['nullable', 'string', 'max:150'],
            'lead.email' => ['nullable', 'required_without:lead.phone', 'string', 'email:rfc', 'max:254'],
            'lead.phone' => ['nullable', 'required_without:lead.email', 'string', 'max:50', 'regex:/^\+?[0-9 .()\-]{7,50}$/'],
            'lead.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'lead.service_interest' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'lead.company_size' => ['nullable', 'integer:strict', 'min:1', 'max:4294967295'],
            'lead.message' => ['nullable', 'string', 'max:5000'],
            'lead.consent' => ['required', 'boolean:strict', 'accepted'],
            'lead.received_at' => ['sometimes', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $errorKeys = collect($validator->errors()->keys());
        $fields = $errorKeys->contains('_envelope')
            ? collect(['envelope'])
            : $errorKeys->sort()->values();

        throw new HttpResponseException(response()->json([
            'result' => 'invalid',
            'errors' => $fields,
        ], 422));
    }
}
