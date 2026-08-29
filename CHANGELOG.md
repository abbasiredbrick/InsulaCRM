# Changelog

## Unreleased

### Fixed

- Admins can now be assigned leads, deals and tasks. On a fresh install the admin
  was the only user but was excluded from every agent list, leaving the "Assigned
  Agent" dropdown with zero options and making it impossible to create a first
  lead (#3).
- The assigned-agent dropdown now renders a placeholder option and an explanatory
  message when no one is assignable, instead of an empty required select that can
  never be satisfied.
- Editing a lead keeps its current owner selectable even after that user has been
  deactivated, so saving no longer silently reassigns the lead.
- Custom tenant-defined roles are now included in agent lists, matching what the
  team invite form already allowed.
- Dashboards, reports, the activity inbox and PDF exports use the same assignable
  user list, so work owned by an admin no longer disappears from team performance.

### Added

- Team members can be deleted, not only deactivated (#3). Deletion requires
  choosing who inherits their leads, deals, tasks, activities, showings and open
  houses — every `agent_id` foreign key cascades, so the records are reassigned in
  the same transaction as the delete. Deleting a member frees their email address
  for reuse; deactivation alone held it forever.
- Guards against deleting your own account or the last remaining admin.
- Business mode can be changed after installation from Settings → General (#4).
  The switch deliberately does not migrate data: the screen shows how many deals
  and leads currently hold a stage or status that does not exist in the target
  mode, and requires typing SWITCH to confirm. The change is written to the audit
  log.
- README section documenting the wholesale/real-estate mode split, so modules
  hidden by the other mode are no longer mistaken for missing files (#4).

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
