<?php

namespace App\Services;

use App\Models\RecycledLead;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search + scoping for the Recycled Leads pool.
 *
 * The pool is a distinct model from Lead, so it gets its own service (the
 * LeadSearchService mandate only covers lead queries). Column set and term
 * matching mirror the canonical lead conventions so behaviour stays uniform.
 */
class RecycledLeadSearchService
{
    /**
     * Columns matched by a free-text search term.
     *
     * @var list<string>
     */
    public const DEFAULT_COLUMNS = ['first_name', 'last_name', 'phone', 'email', 'reference', 'purchased_project', 'unit_no'];

    public function applyTerm(Builder $query, string $term, array $columns = self::DEFAULT_COLUMNS): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term, $columns): void {
            foreach ($columns as $index => $column) {
                $q->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Scope to the shared working set: every pool record in the acting user's
     * tenant. Admins and cold-call agents work the same pool today (mirrors the
     * Cold Calls module); assignment narrows it via the assignee filter.
     */
    public function pooledLeads(): Builder
    {
        return RecycledLead::query();
    }
}
