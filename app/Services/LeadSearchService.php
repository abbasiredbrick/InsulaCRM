<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Application-level lead search.
 *
 * THE canonical way to search leads anywhere in the app — leads list, kanban,
 * CSV export, the Scheduling Hub picker and list search, the global search
 * bar, the showing form and the A2A contract picker. Never re-inline a
 * `where('first_name', 'like', ...)` block or the role-scoping SQL by hand;
 * route every lead search through this service so the behaviour stays uniform
 * and evolves in one place.
 *
 * ## Search-term convention
 * A free-text lead search always matches the same five columns (in this
 * order): `first_name`, `last_name`, `phone`, `email`, `reference`
 * (see DEFAULT_COLUMNS). Add columns only by overriding the column list for a
 * specific call — never by hand-editing the block in a controller.
 *
 * ## Visibility convention (role scoping)
 * - admin        → may search/see every lead in the tenant.
 * - manager      → own leads + direct reports' leads + unassigned + active
 *                  co-agents.
 * - any other role (agent/listing_agent/buyers_agent/...) → own leads + active
 *   co-agents. Unassigned leads are shared and only exposed when explicitly
 *   requested (option `includeUnassigned`), e.g. the Scheduling Hub pickers.
 *
 * The tenant global scope on the Lead model already restricts everything to
 * the acting user's tenant; this service only adds role/agent visibility.
 *
 * ## Label convention
 * `label()` renders the canonical picker label for a lead. Base label comes
 * from `Lead::pickerLabel()` (name, or phone/email for unnamed portal leads),
 * then optionally appends `" · {phone}"` and `" — {property}"`.
 *
 * ## Frontend contract
 * Lead picker endpoints backed by this service return
 * `{ "results": [{ "value": "<lead id>", "label": "<label()>" }] }`, which is
 * the contract consumed by the shared `x-searchable-select` component.
 */
class LeadSearchService
{
    /**
     * Columns matched by a free-text search term (the canonical set).
     *
     * @var list<string>
     */
    public const DEFAULT_COLUMNS = ['first_name', 'last_name', 'phone', 'email', 'reference'];

    /**
     * Apply the canonical free-text match to a query.
     *
     * @param  list<string>  $columns
     */
    public function applyTerm(Builder $query, string $term, array $columns = self::DEFAULT_COLUMNS): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term, $columns): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $q->where($column, 'like', "%{$term}%");
                } else {
                    $q->orWhere($column, 'like', "%{$term}%");
                }
            }
        });
    }

    /**
     * Apply the same term match against a related lead, e.g.
     * `whereHas('lead', ...)` so scheduling records can be searched by their
     * lead exactly the way the leads list searches them.
     *
     * @param  list<string>  $columns
     */
    public function applyRelatedTerm(Builder $query, string $relation, string $term, array $columns = self::DEFAULT_COLUMNS): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $q) => $this->applyTerm($q, $term, $columns));
    }

    /**
     * The lead owners/agents a user may see leads of. Null means "no role
     * restriction" (admin). Managers get themselves + their team; everyone
     * else gets just themselves.
     */
    public function visibleAgentIds(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        return $user->isManager()
            ? array_merge([$user->id], $user->teamUserIds())
            : [$user->id];
    }

    /**
     * Apply the canonical visibility scoping to a Lead (or lead-owning) query.
     *
     * @param  array{includeUnassigned?: bool}  $options
     */
    public function applyVisibleScope(Builder $query, User $user, array $options = []): Builder
    {
        $ids = $this->visibleAgentIds($user);

        if ($ids === null) {
            return $query;
        }

        $includeUnassigned = (bool) ($options['includeUnassigned'] ?? false);

        return $query->where(function (Builder $q) use ($user, $ids, $includeUnassigned): void {
            $q->whereIn('agent_id', $ids)
                ->orWhereHas('leadAgents', fn (Builder $lq) => $lq->whereIn('agent_id', $ids)->where('status', LeadAgent::STATUS_ACTIVE));

            if ($user->isManager() || $includeUnassigned) {
                $q->orWhereNull('agent_id');
            }
        });
    }

    /**
     * A ready-to-use Lead query with the canonical visibility scope applied.
     * Callers then add select/term/order/limit.
     *
     * @param  array{includeUnassigned?: bool}  $options
     */
    public function scopedLeads(User $user, array $options = []): Builder
    {
        return $this->applyVisibleScope(Lead::query(), $user, $options);
    }

    /**
     * The typical one-call entry point: a visible-scoped lead query with the
     * canonical term match already applied. This is what AJAX lead-picker
     * endpoints and the global search use.
     *
     * @param  array{includeUnassigned?: bool, columns?: list<string>}  $options
     */
    public function matchingLeads(User $user, string $term, array $options = []): Builder
    {
        $query = $this->scopedLeads($user, $options);

        return $this->applyTerm($query, $term, $options['columns'] ?? self::DEFAULT_COLUMNS);
    }

    /**
     * Canonical picker label for a lead.
     *
     * @param  array{withPhone?: bool, withProperty?: bool}  $options
     */
    public function label(Lead $lead, array $options = []): string
    {
        $withPhone = (bool) ($options['withPhone'] ?? true);
        $withProperty = (bool) ($options['withProperty'] ?? false);

        $label = $lead->pickerLabel();

        if ($withProperty && $lead->property) {
            $extra = $lead->property->display_name
                ?? $lead->property->optionLabel()
                ?? $lead->property->address;

            if ($extra !== '') {
                $label .= ' — '.$extra;
            }
        }

        if ($withPhone && $lead->phone !== null && ! str_contains($label, $lead->phone)) {
            $label .= ' · '.$lead->phone;
        }

        return $label;
    }
}
