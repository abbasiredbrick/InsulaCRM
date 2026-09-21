# Agent working notes — InsulaCRM (Keystone)

Concise conventions for future sessions. Full deployment/branding details live
in `PROD-DEPLOY.md` (gitignored, contains secrets).

## Lead search — ALWAYS use `App\Services\LeadSearchService`

Lead search is an application-level mechanism. Do NOT hand-roll the
`where('first_name','like',...)` block or the role-scoping SQL in a controller.
Use the service:

- `matchingLeads(User $user, string $term, array $options = [])` —
  scoped query + free-text match in one call (AJAX pickers, global search).
- `applyTerm(Builder $query, string $term, array $columns = [])` — free-text
  match against the canonical columns `first_name, last_name, phone, email,
  reference` (DEFAULT_COLUMNS).
- `applyRelatedTerm($query, 'lead', $term)` — search records (schedules,
  tasks...) by their related lead: `whereHas('lead', ...)`.
- `applyVisibleScope($query, User $user, $options)/scopedLeads(...)` — role
  visibility: admin sees all; manager sees own + reports + unassigned + active
  co-agents; other roles see own + active co-agents (+ unassigned when
  `includeUnassigned => true`, e.g. Scheduling Hub pickers).
- `visibleAgentIds(User $user): ?array` — agent ids a user can filter by
  (null = admin/unrestricted).
- `label(Lead $lead, $options)` — canonical picker label (`pickerLabel()` base,
  plus `" · {phone}"` and `" — {property}"`).

Pointers = `routes/web.php` `schedules.leads.search` and `a2a.leads-search`.
Frontend contract consumed by the shared `x-searchable-select` component:
`{ "results": [{ "value": "<lead id>", "label": "<label()>" }] }`.

Overriding columns is allowed per call (e.g. A2A excludes `email`) — but never
re-inline the block. Update `LeadSearchService` instead, then refactor call
sites to it.

## Search / Filter UI — the live-filter convention

All list/board screens (leads table & kanban, inventory, Scheduling Hub) use the
same instant-search UI. Reuse it; never add a "Search/Filter" submit button.

- Form: `<form method="GET" action="{{ route(...) }}" ... data-live-filter>` with
  named inputs/selects mirroring the controller's query params. No submit
  button — typing (debounced) or changing a control refetches automatically.
- Results: wrap the swappable region (table/board/feed + pagination/tabs) in a
  single `<div data-live-results>...</div>`. `public/js/live-filter.js` fetches
  the full page HTML (Accept: text/html), swaps that node, and mirrors the state
  into the URL via `history.replaceState` — so no AJAX branch is needed in the
  controller.
- "Clear"/"Reset" link: show only when any filter query param is present.
- Re-binding: any JS acting on elements inside the results region must use
  event delegation on `document` (closest() guards) or listen for the bubbling
  `insulacrm:live-updated` event and re-apply (e.g. kanban drag/drop, hub tabs).
- Dropdowns: `public/js/searchable-dropdowns.js` auto-upgrades every native
  `<select>` with 4+ visible options into a searchable combobox that dispatches
  a native `change` event (which the live-filter reuses). `x-searchable-select`
  with `:remote` is the search-as-you-type picker for single-value selections
  (e.g. lead pickers).
- Dependent/cascading dropdowns: when one filter should constrain another (e.g.
  inventory source/agent/community/building), refresh the child `<select>`s from
  a JSON endpoint (`inventory.filterOptions` is the reference); select options
  are read fresh on open by `searchable-dropdowns`, so rebuild is data-driven.

Reference implementations: `resources/views/leads/index.blade.php`,
`leads/kanban.blade.php`, `inventory/index.blade.php`, `schedules/index.blade.php`.

## Commands

- Run the whole suite: `php -d memory_limit=1G vendor/bin/phpunit`
- Lint/format changed PHP files only: `vendor/bin/pint <paths>` (do not run
  repo-wide; it reformats pre-existing files).
- Rebuild optimized autoload after adding classes: `composer dump-autoload -o`.

## Branding

Product name is **Keystone** (never the legacy name). Driven by server-env
`APP_NAME`; see the rebrand checklist in PROD-DEPLOY.md after any deploy.

## Test conventions

Portals/wholesale use `business_mode` (=`realestate`), roles fixtures live in
the base `TestCase`. Full suite is green: **771 tests / 2301 assertions**.
Keep it green; a known-flaky test (`FollowupFeedbackTest::
test_quick_log_posts_the_selected_card_type`) fails occasionally mid-suite —
run isolated to confirm before debugging.