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
     * Constrain the pool to leads whose enquiry date falls within a range.
     *
     * Filtering by the imported Bayut "Date" column (lead_date) is how agents
     * pull the leads due for regeneration — e.g. all enquiries from the last
     * period, or a specific month, before assigning them in bulk.
     */
    public function applyLeadDateRange(Builder $query, mixed $from = null, mixed $to = null): Builder
    {
        if (is_string($from) && trim($from) !== '') {
            $query->whereDate('lead_date', '>=', trim($from));
        }

        if (is_string($to) && trim($to) !== '') {
            $query->whereDate('lead_date', '<=', trim($to));
        }

        return $query;
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
