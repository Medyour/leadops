<?php

namespace App\Domain\LeadProcessing;

use App\Domain\LeadProcessing\Data\DuplicateCheckResult;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

final class DuplicateDetector
{
    public function detect(Lead $lead): DuplicateCheckResult
    {
        $candidateIds = collect();

        if ($lead->normalized_email !== null) {
            $candidateIds = $candidateIds->merge(
                $this->priorCandidates($lead)
                    ->where('normalized_email', $lead->normalized_email)
                    ->pluck('id'),
            );
        }

        if ($lead->normalized_phone !== null) {
            $candidateIds = $candidateIds->merge(
                $this->priorCandidates($lead)
                    ->where('normalized_phone', $lead->normalized_phone)
                    ->pluck('id'),
            );
        }

        /** @var list<int> $ids */
        $ids = $candidateIds
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return new DuplicateCheckResult(
            duplicateOf: count($ids) === 1 ? $ids[0] : null,
            candidateIds: $ids,
        );
    }

    /** @return Builder<Lead> */
    private function priorCandidates(Lead $lead): Builder
    {
        return Lead::query()
            ->whereKeyNot($lead->getKey())
            ->where('status', '!=', LeadStatus::Duplicate->value)
            ->where(function (Builder $query) use ($lead): void {
                $query->where('received_at', '<', $lead->received_at)
                    ->orWhere(function (Builder $query) use ($lead): void {
                        $query->where('received_at', $lead->received_at)
                            ->whereKey('<', $lead->getKey());
                    });
            })
            ->orderBy('received_at')
            ->orderBy('id');
    }
}
