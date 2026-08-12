<?php

namespace App\Http\Requests;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(LeadStatus::class)],
            'classification' => ['nullable', 'string', Rule::enum(LeadClassification::class)],
            'source' => ['nullable', 'string', Rule::enum(LeadSource::class)],
            'assigned_to' => ['nullable', 'string', Rule::in($this->assignees())],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<string> */
    private function assignees(): array
    {
        return array_values(array_unique(config('leadops.routing', [])));
    }
}
