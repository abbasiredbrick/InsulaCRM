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

## Portal lead ingestion

Inbound portal leads (Property Finder, Bayut) must be pulled through
`App\Services\Portals\PortalLeadSyncService::pull()`. Never hand-roll a pull in a
controller or command — the service is what keeps the `is_active` guard, the
per-integration cache lock, and the cursor rule in one place, and those three
used to drift apart per trigger.

- **Triggers (all call the service):** the "Sync leads" button
  (`portal-integrations.sync-leads`, `force: true`), the scheduled commands
  `portals:pull-propertyfinder-leads` / `portals:pull-bayut-leads`
  (`force: true` — the schedule *is* the primary trigger), and the in-app
  catch-up `SyncOverduePortalLeads`, dispatched after the response by the
  `CatchUpPortalLeads` middleware (throttled to 1×/3 min, pulls only
  integrations overdue past `OVERDUE_AFTER_MINUTES`). The pull window and the
  settings-screen "stale" verdict are decoupled: `PortalLeadSyncService` pulls
  once the cursor is 3 min old (`OVERDUE_AFTER_MINUTES = 3`, matching the
  middleware throttle `THROTTLE_SECONDS = 180` and the `everyThreeMinutes`
  schedule), but the badge only cries stale after `STALE_AFTER_MINUTES = 60`
  so a healthy integration is not labelled broken every few minutes. Never
  reuse the sync window for display, or tighten one without the other.
- **The catch-up exists because the prod box had no cron entry** — leads only
  appeared after a manual sync. Keep it, and keep the cron documented in
  PROD-DEPLOY.md. `CatchUpPortalLeads::shouldCheck()` skips under
  `runningUnitTests()` on purpose: the harness fires terminating callbacks, so
  without that guard every feature test would attempt real portal HTTP.
- **Cursor rule:** `leads_last_synced_at` advances only when `$result['error']`
  is null. A partial page failure must surface as an error so the window is
  re-read — never step the cursor over leads that were never fetched.
- **The pull window overlaps by `PULL_OVERLAP_MINUTES = 180`:** each pull
  re-requests up to 3 h *before* the cursor (`$since->min(now() − overlap)`),
  because Property Finder is not guaranteed to list a messaging/"replied"
  WhatsApp lead in the first poll after its `createdAt` — a window that only
  moves forward silently skips those forever (`message_lead_32872954`, the
  "Amer" lead, was exactly that). Overlap re-reads are idempotent because
  `createFromPayload()` de-duplicates by `portal_reference`. Do not remove the
  overlap to save requests, and do not shrink it below what a late-listed lead
  can take to appear.
- **Portal phones are stored canonical, one shape for every number:** every
  inbound route (webhook, PF/Bayut pull) goes through
  `PortalLeadService::canonicalPhone()`, which lands on
  `'+' . ContactNormalizer::phone(...)` — `+971529603039`, never
  `+971 529 603 039`. A share link stores the same compact form, so the same
  client cannot duplicate just because one row kept the separators.
  `ClientShareController::verify()` additionally matches *legacy* rows the
  portal wrote with spaces (a `REPLACE`-digits fallback beside the exact
  match) so a client typing the compact number still lands on the old lead.
- `PortalIntegration` has no `TenantScope`; tenancy is always an explicit
  `where('tenant_id', …)`.

## Lead assignment — the two routing switches

`users.is_active` and `users.receives_leads` are distinct and both matter:

- `is_active` — out of every pool (deactivation, not offboarding; see the
  email-uniqueness note in `SettingsController::destroyAgent`).
- `receives_leads` — the per-agent "Receives new leads" toggle in Settings →
  Team. Opting out removes them from automatic assignment **only**; leads
  already on their book stay put, and manual assignment/claim still works.

**Every automatic path must use `User::scopeReceivingLeads()`** (or
`isInLeadRotation()`), which is `is_active AND receives_leads`. It currently
covers `LeadDistributionService::rotation()` (roundRobin + aiSmart),
`AssignUnclaimedLeads`, `PortalLeadService::findAgentByCode()`, and
`ClientShareController::attributedAgent()` — the last two matter because they
route leads by the agent code embedded in a public touchpoint (portal listing
reference, shared availability link), bypassing the distribution formula
entirely. Do not hand-roll `where('is_active', true)` on an assignment query
again.

**Share links carry attribution, old links do not.** The inventory "Copy share
link" button appends `?agent=<agent_code>` to the URL it builds. A lead created
through a link WITH that parameter goes onto the sharer's book directly
(bypassing the rotation); a link WITHOUT it behaves exactly as before and the
lead goes through normal distribution — never "fix" pre-shared links, and never
change how an unattributed verify performs. The parameter is transport, not a
filter: it is stripped from `share_link_filters`/the lead note, kept on the
redirect URL so a visitor stays on the exact link they were shared, and recorded
as `share_link_agent_code`. `attributedAgent()` only credits an active member
who has not opted out (`receives_leads`), mirroring `findAgentByCode`.

Defaults are deliberately asymmetric, because an unchecked checkbox sends
nothing: `inviteAgent` treats an absent field as **on** (matching the column
default and the pre-checked box), `updateAgent` treats it as **off**.

**The owner is out of the rotation by default.** `assignableRoleIdsFor()`
excludes the `owner` role, so an owner is *never* created through Settings →
Team — the only creation paths are `RegisterController::register()`,
`InstallerService::installApplication()` and `InstallController`, all three of
which set `receives_leads => $adminRole->name !== 'owner'`, plus migration
`2026_09_30_000003_set_owner_receives_leads_false.php` to backfill existing
owner accounts. Don't try to add an owner special case to `inviteAgent`: the
role cannot be posted there. It is a default, not a lockout — an owner can still
opt in from the edit modal, and `isInLeadRotation()` honours that.

## Map Locations — the communities ⇢ buildings master

Settings → Map Locations is admin-only and independent of inventory sources
(which once owned this screen itself). `map_locations` rows are the
building/tower master, `properties` rows are units. New FKs —
`properties.map_location_id → map_locations` and
`map_locations.community_id → communities`, both `nullOnDelete` — are the
source of truth for management; the `sub_community`/`community`/`city` strings
on `properties` stay as snapshots.

- No `TenantScope` on `MapLocation`/`Community`; tenancy is an explicit
  `where('tenant_id', …)` everywhere (`MapLocationService`, and the controller
  guards `ownedLocation()`/`ownedCommunity()` which 403 otherwise). Route-bound
  models must never be trusted tenant-scoped.
- De-dup is **normalized-exact, first-write-wins**: `normalizeName()` collapses
  case/whitespace, `findByName()` matches on it, `ensureMapLocation()` returns
  the existing row so a corrected spelling folds onto it. Re-import never
  duplicates a building; aggressive auto-merge is deliberately avoided — the
  merge screen is where a human collapses near-duplicates.
- Rename cascades to every unit, FK-linked *and* legacy string-linked.
  `rename()` answers `['conflict' => true, 'target' => …]` when the new name
  already belongs to another building (blocked — merge instead). Community
  rename cascades to the locations' `community` and to legacy unit strings;
  communities can be **merged** too (`mergeCommunities()`) so two spellings of
  the same place ("Al Reem Island" / "Reem Island") collapse into one — its
  buildings are re-pointed at the kept community, name snapshots and units
  follow, then the discarded row is deleted.
- Merge re-points `map_location_id`, rewrites linked + legacy unit strings to
  the kept row, then deletes the discard. Deleting a building is blocked while
  it still has units (FK *or* legacy count).
- New units are linked on save: `withMapLocationNotice()` →
  `linkBuildingUnits()`, warning only when the location was just created.
- The New Unit form has ONE searchable picker (`x-searchable-select`, remote
  `inventory.locations-search` → `{results:[{value,label}]}` with label
  `sub_community · community · city — N units`). Picking a building overwrites
  the typed city/community/sub-community server-side
  (`applyPickedBuilding`); the picker contract is what we will reuse to map
  units onto Bayut/PF portal locations.

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

## Studio is a size, not a category — `bedrooms = 0`

A studio is still an **apartment**. The studio/1BR/2BR/3BR split is the *size*
axis, the same axis as the Bedrooms field, so it is recorded as
`bedrooms = 0` — never as a `property_category`. (It briefly was a category;
`isStudio()` still tolerates a stray `property_category = 'studio'` so any row
written during that window renders as Studio rather than "1 BR Apartment".)

The trap is that `0` is **falsy** in PHP, which is exactly how studios used to
vanish from the UI.

- **Render through `Property::bedroomLabel()`**, never
  `$property->bedrooms ? … : null`. It returns `"Studio"` for 0, `"2BR"`
  otherwise, `""` when bedrooms are simply unrecorded.
- **`Property::isStudio()`** is `bedrooms === 0`. `null` must never read as a
  studio — unrecorded bedrooms are a real state.
- `display_name()` produces "Studio Apartment …"; `optionLabel()` skips the
  bedroom half for a studio because `display_name` already carries it, so the
  word never prints twice.
- The inventory form offers a **Studio** checkbox beside Bedrooms that writes
  `0`. Do not ask an agent to type `0` into Bedrooms.
- `AvailabilityIngestService` reads studios from the **Unit Type** free-text
  column (`featuresFromRaw`) and from a `Bedrooms` cell that literally says
  "Studio" (`bedroomCount`). A "Studio" unit type **beats a Bedrooms column
  that says 1** — PM sheets do exactly that, and taking the number is how
  studios get imported and published as 1BR. So re-importing a source whose
  sheet was corrected to "Studio" converts the existing unit in place.
- `detectCategory()` deliberately returns `apartment` for a studio: a studio is
  a size, so apartment-scoped queries and reports must include it.
- **Portals have no studio *type*.** Property Finder gets `type: apartment` +
  `bedrooms: "studio"`; Bayut gets `categoryId: 4` (apartment) + `beds: 0`.
  Both are asserted in `tests/Feature/InventoryStudioTest.php`.
- Category dropdowns are driven off `Property::CATEGORIES`. There is no
  `studio` key and there should not be one.

## A null status is not a cancellation — calendar sync withdraws instead of creates

`sync()` decides create-vs-delete with `shouldRemove()`, which reads the
model's `status`. **A column default only lands in the database, never in the
in-memory model**, so `Showing::create($data)` without an explicit `status`
leaves `$showing->status === null` until the row is re-read. `shouldRemove()`
saw `null !== 'scheduled'`, decided the record was cancelled, and
`removeEvent()` cleared nothing and logged nothing — the event was simply
never created. This silently ate every viewing and meeting created from
`showings.store` / `ScheduleHubController::storeMeeting`, which is how prod
lead `BD2610002`'s viewing ended up on no calendar at all.

Two independent guards, both required:

- `Showing` and `Meeting` declare `protected $attributes = ['status' =>
  'scheduled']` (plus `duration_minutes => 30`) so a fresh model matches its
  column default. `Task` has no status default and does not need one.
- `shouldRemove()` uses `filled($record->status) && $record->status !==
  'scheduled'`. Never compare a possibly-null status directly to
  `'scheduled'`; absence of information is not a cancellation.

Symptom to recognise: a record saves, no `calendar_event_links` row, no log
line, and a manual `sync()` for the same row works fine. Suspect
`shouldRemove()`, not the provider — the provider would have logged.
`test_a_newly_created_viewing_is_pushed_not_withdrawn` in
`tests/Feature/CalendarSyncFailureReportingTest.php` reproduces this; it fails
with 0 links when either guard is removed.

Also part of this contract:

- `sync()` / `removeEvent()` return a `CalendarSyncResult`
  (`app/Services/Cloud/CalendarSyncResult.php`) — never `void`. Every call site
  must surface `->failureMessage()` as the session `warning`; the layout
  already renders it. A sync failure is a warning, never a failed request.
- `calendar_event_links` is the source of truth. `stampLegacyEventColumns()`
  mirrors it onto showing `calendar_*` / `main_calendar_*` (assigned agent
  link, and the owner link when the owners differ) because the reminder job
  still reads those columns; `clearLegacyEventColumns()` nulls them on
  withdrawal.
- `php artisan calendar:reconcile-events` repairs rows with a connected
  involved user but no link. `--dry-run` first. Run it after any fix that
  could have silently skipped syncs.

## Offer letters — the offered unit, the term, and the payment count

Three traps in `OfferLetterService`, all of which produced a letter that
looked fine and was priced off the wrong thing.

**`Deal::property()` is not `Deal::unit()`.** They are *not* two names for
`deals.property_id`:

- `Deal::unit()` → `belongsTo(Property, 'property_id')` — the unit the offer
  was written on, the authoritative one.
- `Deal::property()` → `hasOneThrough(Lead)` — the **lead's** default unit.

`Deal::dealUnit()` (`unit() ?: property()`) is the only correct way to ask
"which unit is this about". `DealCommissionService::propertyFor()` used to
read `property()` directly, so the agent's chosen unit drove the *fees* (via
`buildDefaults` → `dealUnit()`) while the *price and commission rate* came
from the lead's unit. A letter could quote one unit's rent against another
unit's deposit. `propertyFor()` must call `dealUnit()`. The unit is stamped
server-side by `applyChosenUnit()` (tenant-scoped, aborts 422 otherwise) —
never taken from the posted money figures.

**The end date is start + term − 1 day.** `resolveContractDates()` and the
form's `syncEndDate()` must agree: a "one year" tenancy starting 1 Mar 2028
ends **28 Feb 2029**, so the client occupies all twelve months. Note
`addYears()` moves month+day rather than re-deriving them, so
`2028-02-29 + 1y = 2029-02-28`, not a leap-day clamp. The JS builds at UTC
noon so a DST shift cannot walk the date. Only applied when the request
carries `contract_years`, so callers posting an explicit start/end keep them.

**`payment_period` is a count, 1–12, but the column is `string(100)`.**
Letters written before it became a `<select>` hold free text like
"2 Cheques". Always print via `OfferLetter::paymentPeriodLabel()`: a bare
integer 1–12 gets a "Payment"/"Payments" suffix, anything else is returned
verbatim. Numeric suffixing on legacy rows renders "1 Payment Payments".

Also: `deals.show` renders the letter form inline (a collapse), and only for
**rent** deals — a sale deal gets the wholesale `_offers` panel. The
registered `deal.offers.create` route points at `offers.create`, a view that
does not exist and 500s; do not build on it.

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
the base `TestCase`. Full suite is green: **1059 tests / 3414 assertions**,
with the exceptions listed below.
Keep it green; a few tests fail occasionally mid-suite (a different one each
run) — `FollowupFeedbackTest::test_quick_log_posts_the_selected_card_type`,
`LeadManagementTest::test_whatsapp_activity_can_be_logged`,
`TeamManagementTest::test_whatsapp_activity_notifies_manager`,
`TeamManagementTest::test_activity_on_team_lead_notifies_managers`,
`LeadAgentSharingTest::test_co_agent_can_log_activity_and_task_but_not_delete_lead`.
`PortalRoutingTest::test_dedup_prevents_duplicate_from_same_listing_reference`
(a fixture `agent_code` collision, e.g. two `AJ` agents in one test).
All pass in isolation; run isolated to confirm before debugging.

`InventoryTest::test_index_sorts_by_price` is a **pre-existing** failure, not
part of any rotation. It fails in isolation and fails identically on a tree
with the calendar work fully reverted (verified by restoring the touched files
from `HEAD`, not by stashing — see below): `assertLessThan(strpos($asc,
$priciest->unitLabel()), strpos($asc, $oldTown->unitLabel()))` gets two equal
offsets, so `unitLabel()` is not unique enough for the assertion to discriminate
the two rows. `InventoryTest::test_index_filters_by_rent_range` beside it is
flaky — it has failed in about half of runs. Neither is a calendar regression;
do not spend a cycle on it while working here.

The calendar events tests (`CalendarTest::test_calendar_events_returns_json`,
the two `CalendarRealEstateTest` cases) **now pass** and are covered by the
null-status fix above. If they go red again, read that section before assuming
`CalendarController::events()` is at fault.

Never `git stash` to bisect a failure here: the working tree carries a large
body of uncommitted work, so stashing produces a tree that will not even boot
its own tests. Restore individual files from `HEAD` with `git checkout --
<paths>` instead, and copy them aside first if the change is yours.

## Recycled Leads pool import

`RecycledLeadsImportService` is the only way portal exports enter the pool.
Its rules are deliberate — do not relax them in a call site:

- **The phone is the identity.** A row carrying a number matches on that
  number alone; a different number is a different lead even when the email
  matches. Email/WhatsApp username only identify contacts that arrive with no
  number at all.
- **Richer data wins, weaker never does.** A re-upload fills a missing email
  and upgrades a name when the imported name says more (`nameIsRicher`), judged
  on the combined name so a later row may split it differently.
- **`lead_date` is the earliest enquiry**, not the date of the row that created
  the record, and it never moves forward.
- **One contact, both intents.** A contact with a rental *and* a purchase gets
  one row with `alternate_deal_type` set — the pool shows "Rent + Sale" and
  regeneration picks the intent. Regenerating a contact who is already an
  active lead adds the intent to that lead (`additional_intents`) instead of
  refusing or creating a second person.
- Listing references match **per trimmed segment**, so `NF-R -12251230` is a
  rental. Phone normalisation only strips a trunk zero for the GCC allowlist
  (965/966/968/971/973/974) so Turkey/India/Pakistan numbers stay intact.
- Rows tagged `from_agent`, and agent-profile/agent-account enquirers, are
  never imported. A pool row can therefore stay untyped/unnamed when the only
  CSV row that would fill it is one of ours — that is correct, not a gap.

## Free text in views — `<x-linkified>`

Any view rendering user- or portal-supplied free text (notes, activity bodies,
meeting feedback, custom field values) must go through the
`<x-linkified :text="..." />` component, not `{{ }}`. Portal leads arrive with
a listing URL in the notes or in a custom field, and plain `{{ }}` rendered it
as dead text.

- Helper: `App\Helpers\TextRenderHelper::linkify()` (aliased `TextRender` in
  `AppServiceProvider`, next to `Fmt`). Component:
  `resources/views/components/linkified.blade.php`.
- **It escapes first, then injects anchors**, so `{!! !!}` output is safe —
  the only markup in the result is an `<a>` whose href the helper built itself.
  Do not hand-roll a `str_replace` that wraps URLs in `<a>`: that is an XSS.
- Scheme allowlist is `http`/`https` only. A bare `www.` gets `https://`
  prefixed; `javascript:` and `data:` are never linked, they stay inert text.
  Covered by `tests/Feature/LinkifiedTextTest.php`.
- `newlines` prop: default `true` (note-style fields emit `<br>`); pass
  `:newlines="false"` for inline/single-line spots (activity subjects, custom
  field values, anything inside `text-truncate`).
- Already wired: `leads/show` (notes, custom fields, activity subject/body, task
  activity, meeting notes/feedback), `deals/show` (notes, activity subject/body),
  `schedules/index` (feedback), `showings/show`, `recycled/show`,
  `inventory/show`, `deals/_offers`, `open-houses/show`,
  `availability/reviews`, `a2a/show` (contract terms).
- Deliberately NOT linked: `<textarea>` inputs, `Str::limit`-truncated previews
  (a truncated URL is a broken link), and short single-line labels like
  `address`/`title`.

### Portal URLs belong in custom_fields, never in notes

A portal lead's enquiry URL is **one link**, not three. `PortalLeadService` used
to append it to `notes` *and* store it in `custom_fields`, and
`PortalPayloadNormalizer` resolves **both** `url` and `contact_link` from
`responseLink` — so Property Finder leads got the same URL printed under
"Notes", "Listing" and "Contact form". Fixes, in the order they were found:

- Notes carry the client's `message` only; the URL is not appended.
- `leads/show` dedupes the portal rows by URL, keeping the first heading, so a
  PF lead shows one "Listing" row. When `listing_url` and `contact_link`
  genuinely differ, both still show.
- Covered by `LinkifiedTextTest`: one test asserts the URL is rendered exactly
  once, one asserts distinct links both survive, one asserts the ingestion no
  longer copies the URL into notes.

### Backfilling portal URLs out of notes — do not blanket-strip

Migration `2026_09_30_000004_backfill_portal_lead_notes_urls.php` moved 47
production lead notes URLs into `custom_fields`. The two populations needed
opposite treatment, which is why it inspects per row:

- **Property Finder** — notes held *only* the URL, already duplicated in
  `listing_url`/`contact_link`. Cleared.
- **Bayut** — notes held the client's real message with the URL embedded
  ("Hi, I am interested… Link: <url> Reference no.: 10219-GFPUOM") and
  `custom_fields.listing_url` was **empty**. Stripping would have destroyed both
  the only link and the message, so the URL is promoted into `listing_url`
  first. A side benefit: those 33 leads gained a clickable link they never had.

Guards that must not be relaxed:

- **Scoped to `lead_source` in (bayut, property_finder, propertyfinder,
  dubizzle).** Human-written notes with a URL ("See <url>. Call after 5pm") are
  left alone; stripping a URL out of prose yields mangled text ("See. Call").
- **Never promote-then-strip a second URL.** Only the first URL is removed.
- Originals are snapshotted in `lead_notes_url_backfill` (restored by `down()`)
  and in `leads_notes_url_backup` (47 rows, survives a rolled-back migration).
  Both tables are kept until the backfill is signed off.
- `PortalNotesUrlBackfillTest` covers promote/keep-message, clear-duplicate,
  bare-link, leave-manual-alone, and `down()` restore.

### Two portal links, never conflated: property page vs message thread

A portal lead has two links that go to different places:

| field         | opens                                              |
|---------------|----------------------------------------------------|
| `listing_url` | the property page the client was looking at         |
| `contact_link`| the agent's message/WhatsApp thread about it        |

They used to be conflated, so "Listing" opened a chat window. Enforced by
`App\Services\Portals\PortalLinkResolver`:

- **Property Finder** sends ONE url (`responseLink`) for both, and it is a
  *thread*. It is stored as `contact_link` only. PF's property page is then
  resolved from the reference.
- **Bayut** returns `/property/details-{id}.html` (page) and `/pm/{id}/{uuid}`
  (thread). Either can arrive first, so both are classified.
- `/pm/{id}/…` yields the page `/property/details-{id}.html` by derivation —
  safe because leads #10 and #24 share reference 10219-ZVrgZW and carry one URL
  of each shape. Do **not** derive anything for PF.

**Never store `propertyfinder.ae/properties/<slug>`.** Verified against four
live references: it 404s. PF's Enterprise listing payload (probed on
atlas.propertyfinder.com) exposes id/reference/location/price/media/state but no
`url`, `publicUrl` or `slug`, so `PropertyFinderPortalService::listingPageUrl()`
falls back to `referenceSearchUrl()` (`/en/search?q=<ref>`, HTTP 200) instead.

**Never resolve `PropertyFinderPortalService` from the container.** Its
constructor takes a `PortalIntegration`, and Eloquent models need no constructor
arguments — so the container silently supplies an EMPTY integration, auth fails,
and the service falls back to a bogus URL. Build it with the tenant's own
integration (see `ResolvePortalListingLinks::pfService()`). An empty listing
slot is better than a link that 404s.

`leads/show` renders the block as "Portal Links" with rows "Listing" and
"WhatsApp conversation"; identical values still collapse to one row.
`portals:resolve-listing-links` re-derives existing leads (`--dry-run` first).
Backup: `lead_link_split_backup`.
