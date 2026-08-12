<?php

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;

class LeadListQuery
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'external_reference',
        'first_name',
        'last_name',
        'company',
        'status',
        'classification',
        'source',
        'assigned_to',
        'received_at',
    ];

    /** @var list<string> */
    private const SEARCH_COLUMNS = [
        'external_reference',
        'first_name',
        'last_name',
        'company',
        'email',
    ];

    /** @param array<string, mixed> $filters */
    public function apply(Builder $query, array $filters): Builder
    {
        $query->select(self::COLUMNS);

        foreach (['status', 'classification', 'source', 'assigned_to'] as $filter) {
            $query->when($filters[$filter] ?? null, fn (Builder $query, string $value) => $query->where($filter, $value));
        }

        $query->when($filters['search'] ?? null, function (Builder $query, string $search): void {
            $pattern = '%'.$this->escapeLike($search).'%';

            $query->where(function (Builder $query) use ($pattern): void {
                foreach (self::SEARCH_COLUMNS as $index => $column) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $query->{$method}("{$column} COLLATE NOCASE LIKE ? ESCAPE '\\'", [$pattern]);
                }
            });
        });

        return $query->orderByDesc('received_at')->orderByDesc('id');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
