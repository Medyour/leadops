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
            return ['envelope' => null];
        }

        $envelope = json_decode($content, true);

        if (! is_array($envelope)) {
            return ['envelope' => $envelope];
        }

        return ['envelope' => $envelope];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'envelope' => ['required', 'array:lead'],
            'envelope.lead' => ['required', 'array:source,external_reference,first_name,last_name,company,email,phone,country,service_interest,company_size,message,consent,received_at'],
            'envelope.lead.source' => ['sometimes', 'string', 'max:20'],
            'envelope.lead.external_reference' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/'],
            'envelope.lead.first_name' => ['required', 'string', 'max:100'],
            'envelope.lead.last_name' => ['required', 'string', 'max:100'],
            'envelope.lead.company' => ['nullable', 'string', 'max:150'],
            'envelope.lead.email' => ['nullable', 'required_without:envelope.lead.phone', 'string', 'email:rfc', 'max:254'],
            'envelope.lead.phone' => ['nullable', 'required_without:envelope.lead.email', 'string', 'max:50', 'regex:/^\+?[0-9 .()\-]{7,50}$/'],
            'envelope.lead.country' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'envelope.lead.service_interest' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'envelope.lead.company_size' => ['nullable', 'integer:strict', 'min:1', 'max:4294967295'],
            'envelope.lead.message' => ['nullable', 'string', 'max:5000'],
            'envelope.lead.consent' => ['required', 'boolean:strict', 'accepted'],
            'envelope.lead.received_at' => ['sometimes', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $errorKeys = collect($validator->errors()->keys());
        $fields = $errorKeys->contains('envelope')
            ? collect(['envelope'])
            : $errorKeys
                ->map(static fn (string $field): string => str($field)->after('envelope.')->value())
                ->sort()
                ->values();

        throw new HttpResponseException(response()->json([
            'result' => 'invalid',
            'errors' => $fields,
        ], 422));
    }
}
