# Keystone — Complete Feature Pack

*Version 1.2.9 · A self-hosted, multi-tenant real estate & wholesaling CRM.*

> **How to use this deck source:** Every feature is grouped by theme. Section 18 (Screenshot Guide) tells your colleague exactly which pages to capture and what filename to save them under — screenshots drop into `marketing/screenshots/` and the image references in this document will render automatically.

---

## 1. At a Glance

Keystone is an all-in-one property CRM that runs **two products in one**:

| | **Wholesaling** (default) | **Real Estate / Brokerage** |
|---|---|---|
| Pipeline | Prospecting → Disposition → Assigned → Closing | Listing Agreement → Active Listing → Showing → Closing |
| Money field | Assignment fee | Commission |
| Property tools | ARV / MAO worksheet, distress markers, repair costs | List price, days on market, listing status |
| Modules | Disposition Room, buyer matching, list stacking | Listings, Showings, Open Houses, Leases, A2A contracts |
| Roles | Acquisition Agent, Disposition Agent, Field Scout | Listing Agent, Buyers Agent |

**Headline highlights for the deck:**
- 🎯 Lead management with Kanban, dual scoring, and AI qualification
- 🤖 40+ AI features with your own API key (OpenAI, Claude, Gemini, Llama via Ollama)
- ⚙️ Automation: drip sequences, a full workflow engine, campaigns, webhooks
- 🌍 Portals: public buyer portal and client-facing shared-inventory links
- 🏗️ Developer-ready: REST API, plugin system, webhooks, multi-tenant isolation
- 🛡️ Compliance-first: DNC/TCPA enforcement, GDPR tools, 2FA, audit logs

---

## 2. Lead Management

- Full lead CRUD with DataTables: search, sort, and filter by source, status, temperature, and agent.
- 14 pipeline statuses from New to Closed Won/Lost (customizable in Settings).
- 11 default lead sources — cold call, direct mail, website, referral, driving for dollars, PPC, SEO, social media, list import, API, other (customizable).
- **Dual motivation scoring**: automated system score (list stacking + temperature + engagement + property signals) and an independent AI score, displayed side-by-side.
- **Lead Kanban board** with drag-and-drop between status columns.
- Quick actions on the lead detail page: Call, Email, and **WhatsApp** (wa.me, number auto-normalized to the tenant's country dialing code).
- Lead photos with drag & drop upload, lightbox, captions, and per-photo delete.
- Lead assignment history with a full timeline of changes and claim attempts.
- Bulk actions: assign to agent, change status, or delete multiple leads at once.
- CSV import with column-mapping UI, **AI auto-mapping**, saved mappings, and duplicate handling.
- Global keyboard shortcuts, recently-viewed tracking, and a quick-add FAB.
- Saved filter views per user.
- **Field Scout** submission form for drive-by property assessments.
- Contact-type segmentation (seller, buyer, active client, past client) in real estate mode.
- Do Not Contact (DNC) flag with system-wide enforcement on every outreach action.

---

## 3. Deal Pipeline

- Accordion-style Kanban — collapsed stage rows show deal count, total value, and fees; expand to see cards in a responsive grid.
- Native drag-and-drop between stages (even collapsed ones).
- Search bar and agent filter for quick deal lookup.
- Due-diligence countdown with 48-hour urgency alerts.
- Inline quick-edit via slide-over panel: contract price, earnest money, inspection period, closing date.
- Per-deal document uploads (PDF, JPG, PNG).
- Automatic activity logging on every stage change (optimistic UI + toasts).
- **Transaction checklist** per deal with add/check/remove.
- **Offer management**: log, update, and compare offers on each deal.
- Deal activity feed with inline add-from-deal form.
- Stage SLA warnings so nothing stagnates.
- Real estate mode pipeline with 11 stages from lead through closing.

---

## 4. Disposition Room (Wholesale Mode)

- Opens per deal from the deal detail page.
- Manage buyer outreach in one view: match statuses, notes, and next actions.
- Mass-outreach to multiple matched buyers in a single action.
- Automated deal ↔ buyer matching when deals reach the Dispositions stage.

---

## 5. Properties & Inventory

- Full property tracking linked to leads: address, type, beds, baths, sq ft, year built, lot size, condition.
- Distress markers: tax delinquent, pre-foreclosure, probate, code violation, vacant, and more.
- Real-time financial calculator: **ARV, repair estimate, MAO (Maximum Allowable Offer), assignment fee**.
- **Comparable sales (comps)** with an ARV summary worksheet.
- Address-normalization service for deduplication accuracy.
- Property photo galleries with reorder, primary-photo, and portal export.
- **Inventory / units board** (real estate mode) with portal-ready export to **Bayut, Dubizzle, and Property Finder**.
- Portal listing validation and location mapping before publishing.
- Availability-sheet import flows for property-management companies.

---

## 6. Buyers

- Buyer database with company info, contacts, and investment criteria.
- Structured preference fields: property types, zip codes, states, asset classes.
- **Reliability scoring** (decreases when buyers back out of deals).
- **Proof-of-funds (POF) verification** with upload, download, and score recalculation.
- Buyer transaction log to track their track record.
- **Automated deal–buyer matching** with transparent scoring: zip (+30), property type (+25), price range (+20), state (+15).
- One-click notify-buyer for each matched deal.
- CSV import/export and bulk delete.

---

## 7. Automation

- **Drip Sequences**: multi-step builders for SMS, email, call, voicemail, and direct mail, with configurable delays and merge tags. Enroll/unenroll from the lead page with an enrollment-progress widget.
- **Workflow Engine**: event-triggered workflows with a step builder, delays, reorder, manual triggers, and full run logs. Ships with pre-built templates for common follow-up flows.
- **Campaigns**: marketing-spend tracking, lead attribution, and ROI analysis.
- **Email templates** with variables (`{{name}}`, `{{email}}`, `{{company}}`, `{{date}}`) and preview.
- **Document templates + generator**: build contracts/letters with merge fields and generate them per deal (with print view).
- **Outbound webhooks**: on `lead.created`, `deal.stage_changed`, etc. with HMAC-SHA256 signing, 3 retries with exponential backoff, and auto-disable after failures. Pre-built integration recipes page included.
- Scheduled jobs: morning summaries, expiring-contingency alerts, inactive-client digests, drip processing.

---

## 8. The AI Assistant (BYOK — Bring Your Own Key)

Provider-agnostic: **OpenAI, Anthropic (Claude), Google Gemini, Ollama (local), and any OpenAI-compatible endpoint** (LM Studio, vLLM, LocalAI…). Operator pays nothing — tenants use their own API keys.

- **Draft follow-up** content for all 7 activity types — SMS, email, voicemail, call script, direct mail, internal notes, meeting prep — with caller identity injected.
- **Summarize notes** into actionable summaries with motivation level and next steps.
- **Deal analysis**: risk assessment, opportunity score, concerns, recommended actions.
- **Deal stage advisor** with stage-specific next actions.
- **Buyer outreach** emails tailored to each buyer's preferences; **buyer-match explanations**.
- **AI lead scoring** from an independent pass (no circular reasoning with system score).
- **Smart lead qualification**: auto-classifies hot/warm/cold on ingestion.
- **AI task suggestions** with one-click add.
- **Offer strategy**, **property descriptions**, **portal descriptions**, **email subject lines**, and email drafting.
- **CSV auto-mapping** preview of column-to-field suggestions.
- **Weekly digest** of KPIs + recommendations; **pipeline health** widget.
- **DNC risk check** and compliance-flagging.
- **Objection library** for lead conversations.
- **ARV analysis** and **comparable sales** intelligence.
- **Document drafting** and goal recommendations.
- **Briefings** on leads, deals, and buyers (cached).
- **Listing marketing kit** generator (real estate mode).
- **Campaign insights** and buyer risk assessment.
- Full **AI usage history** log for admins.

---

## 9. Reporting & Analytics

- Admin dashboard with KPI cards, ApexCharts, and AJAX-loaded widgets.
- Pipeline bottleneck widget (days per stage, red alerts over 7 days).
- Team performance leaderboard: leads contacted, offers made, deals closed.
- Lead-source ROI widget with per-source cost tracking.
- Conversion funnel: leads → contacted → offer → contract → closed.
- Conversion trend (6-month), lead-to-close velocity, top-agent comparison.
- Goal tracking with forecasting, progress bars, and AI recommendations.
- Reports page with date/agent filters and **CSV export**.
- **PDF reports** for leads, pipeline, and team (print-optimized HTML).
- **Dashboard customization** with per-role defaults and manual widget layout.

---

## 10. Communication & Compliance

- Global **activity inbox** with type, agent, date, and entity filters.
- Calendar with color-coded events (tasks, meetings, calls), click-through to related lead, and AJAX loading.
- **iCal feed sync + Google/Microsoft calendar import/export** (per-user tokens).
- In-app notification bell with unread badge, mark-all-read, and a full notification page.
- 6 queued email notification types: lead assigned, stage changed, due-diligence warning, buyer match, team invite, sequence email.
- Per-tenant **SMTP settings** with test-email button.
- Pluggable **SMS gateway** (dev logger included; add Twilio/Vonage via plugins).
- **DNC & TCPA compliance**: system-wide do-not-contact list (phone + email), single or bulk CSV import, automatic blocking, timezone-based contact restrictions (8 AM–9 PM via ZIP lookup).
- GDPR data export and anonymized deletion for users and contacts.
- Meeting scheduling on leads with follow-up feedback flows.

---

## 11. Portals & Client-Facing Tools

- **Public Buyer Portal** (`/p/{slug}`): brandable property showcase, self-registration, and interest capture.
- **Shared Inventory Links** (`/s/{slug}`): agents share filtered lists of units (e.g. 1BR) with clients; the client verifies identity or self-registers (captured as a lead), browses units, and flags interest — linked straight back to their lead record.
- **Embeddable public web form** for lead capture (branded with tenant logo).
- **Portal integrations**: push listings to Bayut/Dubizzle/Property Finder and accept **inbound lead webhooks** from these portals; location sync and credit usage tracking.

---

## 12. Real Estate Mode Modules

- Listings board (`/listings`) and mandated-sales pipeline (`/listings/mandates`).
- Inventory/unit management with **portal publishing workflow** (Bayut, Dubizzle, Property Finder exports + status sync).
- **Showings** with scheduling and feedback logged into the lead activity.
- **Open houses** with attendee capture.
- **Lease management**: create, renew, and re-launch searches.
- **A2A commission-sharing contracts** between your agents and external agents: create, print, track sent/signed, attach to leads, void.
- **Commission tracking** with per-user commission plans and a "My Commissions" screen.
- **CMA tool** for real estate mode (vs. ARV worksheet in wholesale mode).
- Portal-readiness checklist so listings are publishable before they go live.
- Lead ↔ inventory linking so a lead can be matched to units.

---

## 13. Team, Roles & Permissions

- 5 system roles (Admin, Acquisition Agent, Disposition Agent, Field Scout, Agent) plus real-estate roles (Listing Agent, Buyers Agent) and cold-call agents.
- **Custom roles** with granular permissions — 33 permissions across 9 groups.
- Team page with **manager assignment** and org hierarchy.
- Invite members with welcome notifications; activate/deactivate/reset password/reset 2FA.
- **Delete members safely**: their leads/deals/tasks are reassigned in the same transaction as the delete.
- Impersonation for training and support.
- Goal and compensation tracking per agent.

---

## 14. Administration & Security

- **Multi-tenant isolation** via global scoping — every tenant sees only their own data.
- **Two-factor authentication** (TOTP, QR setup, 8 recovery codes) with admin-enforced "require 2FA" and per-user reset.
- **Pluggable SSO** framework (Google/Azure/Okta via plugins).
- **Full audit log**: searchable, filterable, color-coded, with before/after values and CSV export.
- **API request logging**, rate limiting, and security headers (HSTS, X-Frame-Options, etc.).
- GDPR export/delete for users and contacts.
- Storage: local or **S3-compatible** cloud, configurable per tenant, with connection testing.
- **Database backups**: one-command SQL.gz backup/restore/clean with scheduled daily cleanup.
- **Safe updates**: ZIP upload, staging, automatic pre-update backup, and manual **recovery snapshots**.
- System health check (PHP, Laravel, DB, storage, queue).
- Error-log viewer with resolve/export.
- Factory reset with confirmation.
- Plugin system with ZIP installers, hooks/filters, menu/widget/settings extension points.

---

## 15. Integrations & API

- **REST API** (`/api/v1/`, 20+ endpoints) for leads, deals, buyers, properties, activities, and stats with per-tenant key auth.
- Lead ingestion with automatic source resolution, duplicate detection, and auto-distribution.
- **Interactive API docs** at `/api-docs` + downloadable OpenAPI 3.0 spec.
- **Webform** lead capture for any site.
- **Outbound webhooks** with signing and retries — ready for Zapier, Make, n8n.
- **Plugins** for 2FA, SSO, SMS, and custom logic.
- Google & Microsoft **calendar and drive/photo** connections ("My Cloud").
- **SMS gateway** abstraction with test-send from Settings.

---

## 16. Internationalization

- Locale-aware formatting for 38 currencies, 50 countries, and 50+ timezones.
- Imperial/metric measurements (sq ft ↔ m², acres ↔ hectares).
- **7 language packs included**: English, Dutch, German, French, Spanish, Portuguese, Italian (add any language by dropping in a JSON file).
- Built-in **language editor**: search/filter translations, upload files, inline editing with progress tracking.
- Localized views, calculators, CSV exports, AI prompts, and public forms.

---

## 17. Technical Foundation

| Layer | Tech |
|---|---|
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Tabler Admin (Bootstrap 5) |
| Charts | ApexCharts |
| Kanban | Native HTML5 drag & drop |
| API | REST + per-tenant key auth, OpenAPI spec |
| AI | OpenAI, Anthropic, Gemini, Ollama, OpenAI-compatible (BYOK) |
| Database | MySQL 8.0+ / MariaDB 10.6+ |
| Storage | Local or S3-compatible |
| Deployment | Self-hosted, Docker-ready (nginx + MySQL 8 + Redis), web installer, automatic updates |
| Extras | Dark mode, PWA + offline fallback, mobile-responsive, print-optimized reports |

---

## 18. Screenshot Guide (for the marketing deck)

*The app is self-hosted — screenshots need to be taken manually. Load demo data first for a polished look:*

```bash
php artisan db:seed
# Log in as: admin@demo.com / password  (tenant: Apex Wholesale Properties)
```

Recommended capture order — 14 screenshots cover the whole story. Save images into `marketing/screenshots/` using the exact filenames below so they render in this document automatically:

| # | Screen | URL path | Filename |
|---|--------|----------|----------|
| 1 | Login page | `/login` | `01-login.png` |
| 2 | Dashboard with KPIs + charts | `/dashboard` | `02-dashboard.png` |
| 3 | Lead list with filters + datatables | `/leads` | `03-leads-list.png` |
| 4 | Lead Kanban board | `/leads/kanban` | `04-leads-kanban.png` |
| 5 | Lead detail: dual scoring, photos, quick actions (Call/Email/WhatsApp) | `/leads/{id}` | `05-lead-detail.png` |
| 6 | Deal pipeline (accordion Kanban) | `/pipeline` | `06-pipeline.png` |
| 7 | Deal detail (checklist, documents, offers, activity feed) | `/pipeline/{id}` | `07-deal-detail.png` |
| 8 | Disposition Room (wholesale mode) | `/disposition/{deal}` | `08-disposition-room.png` |
| 9 | Buyer database with match scores | `/buyers` | `09-buyers.png` |
| 10 | Calendar with events | `/calendar` | `10-calendar.png` |
| 11 | AI draft follow-up (lead page AI panel) | open a lead → AI panel | `11-ai-drafts.png` |
| 12 | Reports & analytics with funnel/leaderboard | `/reports` | `12-reports.png` |
| 13 | Workflow builder | `/workflows` | `13-workflows.png` |
| 14 | Settings / Roles & Permissions | `/settings` | `14-settings.png` |

**Nice-to-have bonus shots** (real estate mode — switch Business Mode in Settings > General): Listings board `/listings`, Inventory/units with portal status `/inventory`, Showings `/showings`, Open Houses `/open-houses`, Leases `/leases`, A2A contracts `/a2a`.

**For a public-facing feel:** capture the buyer portal `/p/{slug}` and a shared inventory link `/s/{slug}`.

---

*Generated from the Keystone 1.2.9 codebase. For the full technical README, see `README.md`.*