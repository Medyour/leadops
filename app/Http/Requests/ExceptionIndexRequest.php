<?php

namespace App\Http\Requests;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use Illuminate\Validation\Rule;

class ExceptionIndexRequest extends LeadIndexRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', 'string', Rule::in(array_map(
                static fn (LeadStatus $status): string => $status->value,
                self::statuses(),
            ))],
        ];
    }

    /** @return list<LeadStatus> */
    public static function statuses(): array
    {
        return [
            LeadStatus::Invalid,
            LeadStatus::Duplicate,
            LeadStatus::NeedsReview,
            LeadStatus::Failed,
        ];
    }
}
