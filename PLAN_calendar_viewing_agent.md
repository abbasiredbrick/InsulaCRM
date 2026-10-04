# Plan: calendar sync + viewing-agent bugs (InsulaCRM)

Status: READY FOR REVIEW — no PHP code modified yet; every file below is
verified at HEAD and the exact edit points are cited.

## Bug 1 — timezone: external events pushed in UTC, CRM shows tenant-local

- File: `app/Services/Cloud/CloudCalendarService.php`
- Root cause: `buildEvent()` reads `config('app.timezone')` = **UTC**, but
  every tenant stores its own timezone (`America/New_York` here). So a 2PM
  viewing is pushed as `14:00 UTC` → shows 10:00 in NY on the external calendar.
- Exact edit point: line 177 (`$tz = config('app.timezone');`), used by the
  Showing, Task, and Meeting branches (lines ~181, ~214-215, ~229).
- Proposed change: resolve the timezone from the record's tenant before
  parsing/building the start time, with `config('app.timezone')` as the
  fallback for untethered records. One focused method + one call site.

Recommended sequence: land this first (small, self-contained, testable).

## Bug 2 — showing done by another agent never lands on their calendar

- File: `app/Services/Cloud/CloudCalendarService.php`
- Root cause: `sync()`/`buildEvent()` only ever writes to the calendar of
  `$record->agent_id` (the scheduling agent via `connectionFor()`). There is
  no notion of a *viewing/performing agent*, so when agent B performs a
  showing scheduled by agent A, no event appears on B's calendar.
- Requires (feature): a new `viewing_agent` role + a tenant setting for a
  fixed/percentage "viewing agent" fee (like the existing listing/cold-call
  agent commission), plus reassignment routing in `sync()`.

This is a feature build (role + settings + commission + calendar routing),
bigger than bug 1 — confirm scope before implementing.

## Bug 3 — cannot log viewing/meeting/task feedback; merge feedback section

- Current state: feedback is spread across separate viewings/meetings/tasks
  areas with no single "log feedback" affordance and no viewing-agent concept.
- Requires (feature): merged feedback UI + the viewing-agent commission
  settings above. Confirm scope.

## What is intentionally NOT deployed yet

The two WIP blade edits (My Commissions nav, lead commission panel) were
reverted so only intentional, tested changes ship.
