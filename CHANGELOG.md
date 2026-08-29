# Changelog

## Unreleased

### Added

- WhatsApp quick action on the lead detail page, next to Call Lead. Opens a
  wa.me conversation in a new tab and respects the do not contact flag, so it is
  hidden for any lead marked as such. Originally contributed by
  @klaraheesen-eng in #1.
- Phone numbers are normalised for wa.me using the dialing code of the tenant's
  configured country rather than a fixed one. Numbers already in international
  form, whether written with a plus or a 00 prefix, are passed through untouched,
  and numbers that already carry their country code are not doubled up. National
  numbers are handled both for countries that use a leading trunk zero and for
  those that do not, such as the North American numbering plan.
- `TenantFormatHelper::dialingCode()` alongside the existing country list, and
  `TenantFormatHelper::forgetTenant()` for processes that handle several tenants
  in turn.

### Changed

- The WhatsApp button uses a `.btn-whatsapp` class instead of inline styles.

### Documentation

- Added `ROADMAP.md` recording the current state, the release policy, work that
  is deferred by decision, and known gaps that are not yet tracked as issues.
- Added `CONTRIBUTING.md` covering setup, the test commands, and the domain
  rules that changes most often trip over: do not assume a country, respect the
  business mode, scope queries by tenant, honour Do Not Contact, and check
  cascading foreign keys before deleting.
- README lists the lead quick actions and links to the roadmap and contributing
  guide.

## 1.1.0 - 2026-08-29

First maintenance release. Unblocks new installations, closes a data
retention gap in team management, and removes the need to reinstall in
order to change business mode.

### Fixed

- Admins can be assigned leads, deals and tasks. On a fresh install the admin
  was the only user but was excluded from every agent list, so the required
  "Assigned Agent" dropdown rendered with no options at all and the first lead
  could never be saved. Lead validation had always accepted the admin; only the
  dropdown query excluded them. (#3)
- The assigned agent dropdown now shows a placeholder option, plus a message
  explaining what to do when nobody is assignable, instead of an empty required
  select that cannot be satisfied.
- Editing a lead keeps its current owner selectable after that user has been
  deactivated, so saving the form no longer silently reassigns the lead.
- Custom roles defined by a tenant are now recognised in agent lists. The team
  invite form already allowed them, but no agent list matched them, so anyone
  holding a custom role was invisible.
- Dashboards, reports, the activity inbox and PDF exports all resolve agents
  the same way, so leads and deals owned by an admin no longer disappear from
  team performance figures.

### Added

- Team members can be deleted, not only deactivated. (#3)
  Deletion asks who inherits the member's records: leads, deals, tasks,
  activities, showings and open houses are reassigned inside the same
  transaction as the delete. Every agent_id foreign key cascades, so removing
  the row without reassigning first would have destroyed that member's entire
  book of business.
- Deleting a member frees their email address for reuse. Addresses are globally
  unique and the users table has no soft deletes, so a deactivated member held
  their address permanently and it could never be recovered through the UI.
- Deleting your own account, or the last remaining admin, is refused.
- Business mode can be changed after installation, under Settings > General.
  Previously the mode was fixed at install time and only a reinstall or a
  direct database edit could change it. (#4)
- The mode switch reports its own blast radius before you commit to it: the
  screen counts how many deals and leads currently hold a stage or status that
  does not exist in the target mode, and requires typing SWITCH to confirm.
  The change is written to the audit log.

### Changed

- Switching business mode deliberately does not migrate existing records.
  Stages and statuses are stored as raw values and the two modes use different
  vocabularies, so remapping is a judgement call that belongs to the operator.
  Affected records keep working and display their original value until remapped.

### Documentation

- README now documents the wholesale and real estate mode split, including
  which modules belong to each mode. Modules hidden by the opposite mode were
  being reported as files missing from the release. (#4)
- README notes that the Disposition Room has no top level navigation entry and
  is opened per deal from the deal detail page.

## 1.0.0 - 2026-03-28

Initial open-source release.

### Core

- Multi-tenant architecture with role-based access and 33 permissions across 9 groups
- Dual business mode (wholesale | realestate) with mode selection during installation
- Web installer with server requirement checks, database setup, and demo data seeding

### Lead Management

- Lead management with kanban board, bulk actions, CSV import/export, and stacking detection
- Contact type segmentation (seller, buyer, active client, past client) in real estate mode
- Global keyboard shortcuts, recently viewed tracking, quick-add FAB
- Column sorting, saved filter views, inline status changes
- Lead assignment history with timeline of all changes and claim attempts
- CSV import with preview, saved column mappings, and duplicate handling

### Deal Pipeline

- Deal pipeline with stage tracking, document uploads, and buyer matching
- Real estate pipeline with 11 stages from lead through closing
- Disposition room for managing buyer outreach with status tracking and bulk actions (wholesale mode)
- Inline quick-edit on pipeline cards, stage SLA warnings

### Buyers & Properties

- Buyer database with criteria matching and automated notifications
- Buyer verification with proof-of-funds upload and automated scoring
- Buyer transaction logging for track record
- Public buyer portal with property showcase, self-registration, and custom branding
- Property management with comparable sales and due diligence tracking
- CMA tool for real estate mode, ARV worksheet for wholesale mode

### AI Features

- AI-powered lead scoring, motivation analysis, and auto-qualification
- AI briefings on lead, deal, and buyer pages with cached responses
- AI lead snapshot, deal analysis, stage advice, DNC risk check
- AI email drafting, subject line generator, objection responses, offer strategy
- AI pipeline health dashboard widget
- AI smart routing distribution method
- Scheduled AI pipeline digest, follow-up suggestions, and stale deal alerts
- Support for OpenAI, Claude, Gemini, and Ollama providers

### Automation

- Email sequences with automated drip campaigns
- Workflow automation engine with event triggers, step builder, delays, and run logs
- 5 pre-built workflow templates for common follow-up sequences (real estate mode)
- Campaign tracker for marketing spend, lead attribution, and ROI

### Documents & Reporting

- Document template system with merge fields and deal-based generation
- Listing marketing kit with AI-generated content (real estate mode)
- Investor packet with property overview, ARV summary, and comps (wholesale mode)
- Built-in reporting with PDF export
- Goal tracking with forecasting, progress bars, and AI recommendations

### Communication & Notifications

- Activity logging across calls, emails, SMS, voicemail, direct mail, and notes
- Global activity inbox with type, agent, date, and entity filters
- Webhook integrations with HMAC signature verification
- Morning summary, expiring contingencies, and inactive client digests
- Notification delivery preferences (instant email or daily digest)

### Administration

- Custom roles and permissions management
- Dashboard customization with per-role tenant defaults
- Multi-language support with built-in translation editor
- Two-factor authentication with enforced 2FA option
- SSO support
- Safe update manager with ZIP upload, staging, and automatic backup
- Manual recovery snapshots with restore capability
- GDPR compliance tools
- S3-compatible cloud storage support
- Automated database backups
- Plugin system with hooks, filters, and extension points
- Dark mode with per-user theme preference
- Do Not Contact list management
- Calendar with iCal feed sync
- Showings and open house management (real estate mode)
- PWA support with service worker and offline fallback
- Error logging and bug report viewer
- API with key authentication and request logging
