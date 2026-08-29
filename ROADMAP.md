# Roadmap and Known Gaps

Where the project stands and what is deliberately outstanding. Update this
alongside any release.

## Current state

| | |
|---|---|
| Latest release | 1.1.0 (2026-08-29) |
| `main` | Contains unreleased work destined for 1.2.0 |
| Unreleased on `main` | WhatsApp quick action for leads |

See `CHANGELOG.md` for the full history and for what is currently sitting
unreleased.

## Release policy

**Never re-release a version number that has already been published.**
`UpdateManagerService` rejects any package whose version is not strictly greater
than the installed one:

```php
if (! version_compare($targetVersion, $currentVersion, '>')) {
    throw new RuntimeException("This package targets version {$targetVersion}. ...");
}
```

Republishing an existing version therefore makes the new contents unreachable
for anyone already on that version, and it breaks the meaning of "which version
are you running" when supporting users.

Other conventions:

* `VERSION` is the single source of truth. `config/app.php` reads it, and every
  other `'1.0.0'` in the codebase is only a fallback default.
* Semantic versioning. New functionality is a minor bump even when it arrives
  next to bug fixes.
* Merging to `main` and releasing are separate decisions. Work can sit on `main`
  unreleased while a recent release soaks.

## Open with users

* **#3 Issue adding a lead.** Fixed in 1.1.0. Left open until a reporter
  confirms they can delete the stuck team member and reuse the email address.
* **#4 Missing files for multiple modules.** Answered: the modules are hidden by
  business mode, not absent. Left open pending confirmation.
* **Leads not reaching the pipeline.** Raised by a commenter on #3 and never
  investigated. It needs its own issue from the reporter before work starts.
  This is the only outstanding user report with no analysis behind it.

## Deferred by decision

### Full WhatsApp Business API integration

**Deferred, not rejected.** 1.2.0 ships a `wa.me` deep link only.

A real integration means the WhatsApp Business API: Meta app review, per tenant
credentials, webhook handling for inbound messages, message template approval,
and delivery receipts. It would also intersect with the existing DNC and TCPA
compliance work, because actually sending messages carries consent obligations
that opening a link does not.

Revisit when users ask for it. As of 1.2.0 the only signal was a single
contributed pull request, with no requests from operators.

### Guided remap after a business mode switch

Switching business mode deliberately does not migrate data. Stages and statuses
are stored as raw values and the two modes use different vocabularies, so
records created under the previous mode keep their old value until an operator
remaps them. Settings shows how many records would be affected before the
switch, but the remapping itself is manual.

A guided bulk remap, mapping each old stage to a new one, is the natural
follow up if operators actually switch modes in practice.

## Known gaps

* **Custom roles are not selectable in the team UI.** `SettingsController::index`
  filters the role list down to the business mode's system roles, which also
  removes any tenant defined custom role, while `inviteAgent` accepts them. A
  custom role can be created under Settings but cannot then be assigned to a new
  team member through the form. Agent lists themselves do recognise custom roles
  since 1.1.0.
* **Role lookups are not tenant scoped.** Several `Role::whereIn('name', ...)`
  queries match on name alone. System roles are global so this is currently
  harmless, but a tenant custom role sharing a name with another tenant's would
  match across tenants.
* **The OpenAPI spec version is hardcoded.** `ApiDocsController` reports
  `'version' => '1.0.0'` rather than reading `config('app.version')`. Arguably
  correct if it is meant to describe the API contract rather than the app, but it
  is currently ambiguous and undocumented.
* **No `CONTRIBUTING.md`.** The first external pull request received a review
  and then stalled for three months. Contribution expectations, the test command,
  and the fact that phone and locale behaviour must not assume a single country
  are all worth writing down.

## Testing notes

* The suite is PHPUnit over an in memory SQLite database. Run it with
  `php artisan test`.
* A working copy needs `composer install` and a `.env` file before tests will
  pass. `CheckInstalled` treats a missing `.env` as "not installed" and every
  authenticated route then redirects to `/install`, which surfaces as a wall of
  302s rather than an obvious configuration error. Copy `.env.example` and run
  `php artisan key:generate`.
* Coverage is at the controller, model and route level. There is no browser
  based end to end testing, so Blade rendering is verified only by asserting on
  response content.
