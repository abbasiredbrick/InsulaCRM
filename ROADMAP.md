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
* **Custom role names are unique across every tenant.** `SettingsController`
  generates a role slug and resolves collisions globally:

  ```php
  while (Role::where('name', $name)->exists()) {
      $name = $baseName . '_' . $counter++;
  }
  ```

  A tenant that creates a role named `sales` causes the next tenant to receive
  `sales_1`, so a tenant admin can infer which role names already exist
  elsewhere on the instance by watching the suffix. It needs an authenticated
  admin and reveals nothing beyond role names, but the uniqueness check belongs
  scoped to the tenant.
* **One role lookup is still unscoped.** `DealController` resolves notification
  recipient roles with `Role::whereIn('name', ...)` on name alone. The recipient
  query is filtered by `tenant_id` immediately afterwards, so nothing crosses
  tenants today; it is a correctness wart rather than a leak.
* **The OpenAPI spec version is hardcoded.** `ApiDocsController` reports
  `'version' => '1.0.0'` rather than reading `config('app.version')`. Arguably
  correct if it is meant to describe the API contract rather than the app, but it
  is currently ambiguous and undocumented.

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
