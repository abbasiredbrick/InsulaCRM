# Keystone — Feature List

**CRM for property brokers and wholesalers** — a self-hosted, multi-tenant workspace that
runs two property businesses in one: wholesaling and brokerage. Lead management, deal
pipeline, disposition room, portal publishing, automation and a bring-your-own-key AI
assistant on a single platform.

## Core platform

- **Self-hosted, multi-tenant** — Laravel 12 + MySQL/MariaDB, Docker-ready, web installer,
  and automatic updates. Every tenant is isolated via global scoping.
- **Two business modes in one workspace**:
  - **Wholesaling** — disposition room, ARV/MAO worksheet, assignment fees, buyer matching.
  - **Brokerage** — listings, showings, open houses, leases, commissions, CMA tool.
- **Role-based access control** — 5 system roles plus broker / real-estate / cold-call
  roles, and custom roles with 33 granular permissions across 9 groups.
- **Team management** — manager hierarchy, invites, activation/deactivation, safe member
  deletion with automatic data reassignment, and impersonation for training.
- **Dark mode, PWA + offline fallback, mobile-responsive UI**, print-optimized reports.

## Lead management

- **Full lead CRUD with DataTables** — search, sort and filter by source, status,
  temperature and agent; saved filter views per user.
- **14 pipeline statuses** and **11 default lead sources**, both customizable.
- **Dual motivation scoring** — an automated system score (list stacking, temperature,
  engagement, property signals) and an independent AI score, displayed side-by-side.
- **Lead Kanban board** with drag-and-drop across columns and a quick-add FAB.
- **Quick actions on the lead detail page** — Call, Email and WhatsApp with
  auto-normalized dialling codes.
- **Lead photos**, assignment history timeline, bulk actions (assign / status / delete),
  and CSV import with column mapping and **AI auto-mapping**.
- **Field Scout submission form** for drive-by property assessments.
- **Do-Not-Contact (DNC) flag** enforced system-wide on every outreach action.

## Deal pipeline

- **Accordion-style Kanban** — collapsed stages show deal count, total value and fees;
  native drag-and-drop between stages.
- **Due-diligence countdown** with 48-hour urgency alerts and stage SLA warnings.
- **Per-deal** documents, transaction checklist, offer management, and activity feed with
  automatic logging on every stage change.
- **Inline quick-edit slide-over** for contract price, earnest money, inspection period
  and closing date.

## Disposition Room (wholesale mode)

- Opens per deal with buyer outreach, match statuses and next actions in one view.
- **Automated deal ↔ buyer matching** when deals reach the Dispositions stage.
- **Mass outreach** to multiple matched buyers in a single action.

## Properties & inventory

- Full property tracking linked to leads: type, beds, baths, sq ft, condition and year built.
- **Distress markers** — tax delinquent, pre-foreclosure, probate, code violation, vacant.
- **ARV / repair / MAO calculator** with comparable-sales (comps) worksheet and
  address-normalization for deduplication.
- **Inventory / units board** (brokerage mode) with **portal publishing** to Bayut,
  Dubizzle and Property Finder — validation, location mapping and status sync.
- Property photo galleries with reorder, primary-photo and portal export.
- Availability-sheet import flows for property-management companies.

## Buyers

- Buyer database with investment criteria, company info and contacts.
- **Reliability scoring** (decreases when buyers back out) and **proof-of-funds (POF)
  verification** with upload, download and score recalculation.
- Buyer transaction log, CSV import/export, bulk delete.
- **Automated deal–buyer matching** with transparent scoring — zip, property type, price
  range, state — and one-click notify-buyer on each match.

## Scheduling & communication

- **Calendar** with color-coded events (tasks, meetings, calls, viewings), click-through to
  the related lead, and AJAX loading.
- **Scheduling hub** — viewings, meetings and tasks for the whole team.
- **iCal feed sync + Google / Microsoft calendar** import & export.
- **Global activity inbox** — Calls, SMS, Email, WhatsApp, Meetings — with type, agent,
  date and entity filters.
- Open houses with attendee capture; meeting scheduling with follow-up feedback flows.
- In-app notifications (bell, unread badge, six queued email types), per-tenant SMTP, and
  a pluggable SMS gateway.

## Automation

- **Drip sequences** — multi-step builders for SMS, email, call, voicemail and direct mail
  with configurable delays and merge tags.
- **Workflow engine** — event-triggered workflows with step builder, delays, manual
  triggers, pre-built templates and full run logs.
- **Campaigns** — marketing-spend tracking, lead attribution and ROI analysis.
- **Email & document templates** — variables, previews, and a document generator that
  builds contracts per deal with a print view.
- **Outbound webhooks** — `lead.created`, `deal.stage_changed` and more, HMAC-SHA256
  signed, 3 retries with backoff, auto-disable on failure.
- Scheduled jobs: morning summaries, expiring-contingency alerts, inactive-client digests.

## AI assistant (BYOK - Bring Your Own Key)

- **Provider-agnostic** — OpenAI, Anthropic Claude, Google Gemini, Ollama (local), and any
  OpenAI-compatible endpoint (LM Studio, vLLM, LocalAI).
- **Draft follow-up** for all 7 activity types with caller identity injected.
- **Summarize notes**, deal analysis & risk scoring, stage-specific action advisor.
- **Buyer outreach emails**, buyer-match explanations, and independent AI lead scoring.
- Smart qualification (hot/warm/cold on ingestion), AI task suggestions, offer strategy,
  property and portal descriptions, subject lines, CSV auto-mapping.
- Weekly KPI digest, pipeline health widget, ARV/comps intelligence, document drafting,
  listing marketing kit, campaign insights, and a full **AI usage history** log.

## Reporting & analytics

- Dashboard KPI cards, ApexCharts and AJAX-loaded, **customizable widgets** with per-role
  defaults.
- Pipeline bottleneck widget, **team performance leaderboard**, and **lead-source ROI**.
- **Conversion funnel** (leads → contacted → offer → contract → closed), 6-month trend,
  lead-to-close velocity and top-agent comparison.
- Goal tracking with forecasting and AI recommendations.
- **PDF reports** for leads, pipeline and team; CSV exports with date/agent filters.

## Real-estate mode modules

- Listings board and mandates pipeline; **listings readiness checklist**.
- **Showings** with scheduling and feedback logged to the lead activity.
- **Open houses** with attendee capture.
- **Lease management** — create, renew, re-launch searches.
- **A2A commission-sharing contracts** between your agents and external agents — create,
  print, track sent/signed, attach to leads, void.
- **Commission tracking** with per-user plans and a "My Commissions" screen.
- Lead ↔ inventory linking.

## Compliance & security

- **DNC & TCPA** — system-wide do-not-contact list (phone + email), single/bulk CSV import,
  automatic blocking, and timezone-based 8 AM–9 PM contact restrictions.
- **GDPR** data export and anonymized deletion for users and contacts.
- **Two-factor authentication** (TOTP, QR setup, 8 recovery codes) with admin-enforced
  requirement and per-user reset.
- **Full audit log** — searchable, filterable, color-coded, with before/after values and
  CSV export.
- API request logging, rate limiting, security headers (HSTS, X-Frame-Options).
- Storage on local disk or **S3-compatible cloud**; one-command database backups;
  **safe updates** with pre-update backups and recovery snapshots; system health check;
  error-log viewer; factory reset; ZIP-installer **plugin system**.

## Portals & client-facing tools

- **Public buyer portal** (`/p/{slug}`) — brandable property showcase, self-registration,
  and interest capture.
- **Shared inventory links** (`/s/{slug}`) — agents share filtered lists of units with
  clients; identity-verified viewing requests create leads automatically.
- **Embeddable public web form** for lead capture, branded with the tenant logo.
- **Portal integrations** — push listings to Bayut / Dubizzle / Property Finder and accept
  inbound lead webhooks, with location sync and credit tracking.

## Internationalization & localization

- Locale-aware formatting for **38 currencies, 50 countries and 50+ timezones**.
- Imperial/metric conversions (sq ft ↔ m², acres ↔ hectares).
- **7 language packs** included — English, Dutch, German, French, Spanish, Portuguese,
  Italian — with a built-in language editor to add or edit any language.
- Localized views, calculators, CSV exports, AI prompts and public forms.

## Integrations & API

- **REST API** (`/api/v1/`, 20+ endpoints) for leads, deals, buyers, properties,
  activities and stats, with per-tenant key auth.
- **Interactive API docs** + downloadable OpenAPI 3.0 spec.
- Lead ingestion with source resolution, duplicate detection and auto-distribution.
- Outbound webhooks ready for Zapier, Make and n8n.
- Google & Microsoft calendar and drive/photo connections ("My Cloud").
- Portal integrations and SMTP/SMS gateways configurable per tenant.

---

*Screenshots of the live product (tenant demo data) are in `marketing/screenshots/` and the
marketing deck is `marketing/Keystone-Marketing-Deck.html` (PDF:
`marketing/Keystone-Marketing-Deck.pdf`).*