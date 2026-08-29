# Contributing to InsulaCRM

Contributions are welcome, including small ones. This document covers what you
need to get running and the handful of domain rules that are easy to trip over
in this codebase.

## Getting set up

```bash
git clone https://github.com/InsulaCRM/InsulaCRM.git
cd InsulaCRM
composer setup
```

`composer setup` installs dependencies, creates `.env` from `.env.example`,
generates an application key, runs migrations, and builds front end assets.

If you install by hand instead, **do not skip the `.env` file**. `CheckInstalled`
treats a missing `.env` as "not installed" and redirects every authenticated
route to `/install`, so the app and the test suite both fail with a wall of 302
responses rather than an obvious configuration error.

## Running the tests

```bash
composer test          # config:clear, then the full suite
php artisan test       # the suite on its own
php artisan test --filter SomeTest
vendor/bin/pint        # code style
```

Tests run against an in memory SQLite database, so they are fast and need no
setup beyond the above. Please add coverage for anything you change; the suite
is the only automated verification this project has, since there is no browser
based end to end testing.

## Things this codebase cares about

Most rejected or reworked changes come down to one of these.

### Do not assume a country

InsulaCRM is used internationally. Phone numbers, addresses, dates, currency and
measurement units all vary by tenant, and every tenant has a `country`, `locale`,
`currency`, `date_format` and `measurement_system` set under Settings > General.

Read those through `TenantFormatHelper` (aliased as `Fmt`) rather than hardcoding
a value. A national phone number, for example, cannot be turned into an
international one without knowing the tenant's country: some countries use a
leading trunk zero and some, such as those in the North American numbering plan,
do not.

### Respect the business mode

The app ships two products in one. A tenant is either in `wholesale` or
`realestate` mode, and that decides which pipeline stages, lead statuses, roles
and modules exist. Never hardcode a stage or status list; get them from
`BusinessModeService`. If a feature only makes sense in one mode, gate it
explicitly. See the Business Modes section of the README.

### Scope queries by tenant

This is a multi-tenant application. Models use `TenantScope`, but any query that
bypasses the scope, or that looks something up by name rather than by tenant,
needs its own `tenant_id` filter. Cross-tenant leakage is the most serious class
of bug this project can ship.

### Honour Do Not Contact

Leads carry a `do_not_contact` flag, and the app has DNC and TCPA compliance
features built around it. Any feature that contacts a lead, or that offers a way
to contact them, must be hidden or disabled when that flag is set.

### Check your foreign keys before deleting

Most `agent_id` foreign keys cascade on delete. Deleting a user without
reassigning their records first will take their leads, deals, tasks, activities,
showings and open houses with them. If you add a destructive action, reassign or
soft delete inside the same transaction, and cover it with a test that proves
the related records survive.

## Pull requests

Keep changes focused; one concern per pull request. Describe what you changed
and how you verified it.

You will get a review. If changes are requested and you would rather not carry
on, say so or simply leave it: a stalled pull request will be picked up and
finished rather than closed, and you keep authorship credit through a
`Co-Authored-By` trailer and a mention in the changelog. Contributions are not
discarded because their author moved on.

## Commits and changelog

Write commit messages that explain **why**, not only what. If the reason is
subtle, such as a foreign key that cascades or an assumption that only holds in
one country, put it in the message. Future maintainers read the history.

Add an entry under `## Unreleased` in `CHANGELOG.md` for anything user visible,
grouped under Fixed, Added, Changed or Documentation.

Note that merging and releasing are separate steps here. Work can sit merged on
`main` for a while before it is tagged, usually so that a recent release has time
to soak. See `ROADMAP.md` for the release policy, current state, and the known
gaps that are already on the list.
