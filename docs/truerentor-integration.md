# TrueRentor integration (Keystone side)

Keystone pulls a TrueRentor operator's availability through the **existing**
`AvailabilitySource` machinery — TrueRentor is just one more source type, so it
inherits the import history, reconciliation and "unit dropped off the list"
review queue for free.

The API contract lives in the TrueRentor repo: `docs/integration.md` +
`docs/integration/openapi.yaml`.

## How it works

1. A broker registers with a TrueRentor operator (TrueRentor's `/broker/register`).
2. In Keystone → **Inventory Sources → Connect TrueRentor**, the broker either:
   - **Connects via OAuth** — signs in to TrueRentor and approves; Keystone
     stores the returned token automatically, or
   - **Pastes a token** — copied from the TrueRentor broker portal
     (`/portal/availability` → *CRM / PMS integration*).
3. Keystone creates an `availability_sources` row pointing at
   `<base>/api/v1/availability/<org_slug>` with the bearer token (encrypted at rest).
4. A scheduled command pulls the feed every 6 hours; the existing "Sync now"
   button pulls on demand. Units the operator no longer lists are reconciled via
   `AvailabilityReview` (keep listed / unlist).

## Environment

```dotenv
TRUERENTOR_BASE_URL=https://truerentor.vercel.app
TRUERENTOR_CLIENT_ID=keystone
TRUERENTOR_CLIENT_SECRET=…   # must match TrueRentor's INTEGRATION_OAUTH_CLIENTS allow-list
```

`TRUERENTOR_CLIENT_SECRET` must equal the `client_secret` for `client_id=keystone`
in TrueRentor's `INTEGRATION_OAUTH_CLIENTS`, and the callback
`<keystone>/availability-sources/truerentor/callback` must be in that client's
`redirect_uris`.

## Scheduled sync

```text
php artisan availability:sync-urls            # all tenants
php artisan availability:sync-urls --tenant=5 # one tenant
```

Registered in `bootstrap/app.php` as `->everySixHours()`. It iterates every
`availability_sources` row with a `url`, fetches with the stored bearer token,
and reconciles via `AvailabilityIngestService` (same path as the "Sync now"
button).

## Reverse direction

Keystone → TrueRentor (e.g. pushing a lead back) uses Keystone's own public
API (`tenants.api_key`, `/api/v1/*`, documented at `/api-docs`) or TrueRentor's
`submit_lead` flow via the unit's `share_path`.
