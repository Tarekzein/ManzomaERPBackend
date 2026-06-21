# ManzomaERP SRS Compliance Matrix

Last reviewed: 2026-06-15

Status values:

- `Complete`: implemented in backend and frontend with automated coverage.
- `Partial`: useful implementation exists, but one or more SRS behaviors remain.
- `Missing`: no production implementation exists.
- `Approved deviation`: intentionally differs from the SRS.

## Approved Architectural Deviations

| SRS area | Status | Decision |
| --- | --- | --- |
| Database-per-tenant tenancy | Approved deviation | Keep the single database and enforce company isolation with `company_id`, middleware, policies, and automated isolation tests. |
| `/api/v1` API versioning | Approved deviation | Keep the existing unversioned `/api/*` contract to preserve frontend and integration compatibility. |

## Functional Requirements

| Requirement group | Status | Notes |
| --- | --- | --- |
| Authentication and default RBAC | Partial | Email/password, password complexity, login attempts, default roles, API tokens, forced reset, and custom company roles exist. OAuth SSO and full TOTP API/frontend flows remain external follow-up work. |
| HR | Complete | Employee, organization, leave, attendance, payroll, payslips, documents, self-service, ATS, and reports are implemented. |
| Finance | Complete | Accounts, ledger, AP/AR, reconciliation, statements, budgets, currency, tax, and periods are implemented. |
| Inventory | Partial | Catalog, stock, warehouses, movements, reorder alerts, valuation, lookup, and reports exist. Scanner hardware workflows remain environment-specific. |
| Sales and purchasing | Partial | Core workflows, PDFs, Inventory, and Finance integration exist. Custom document-template management remains. |
| CRM | Partial | Contacts, pipeline, activities, tasks, segments, campaign event ingestion, and reports exist. Live Mailchimp/SendGrid campaign sending remains. |
| Projects | Partial | Projects, tasks, timeline, time, files, expenses, and reports exist. Advanced Gantt and task-board interactions remain. |
| Reporting and BI | Partial | Widgets, prebuilt/custom reports, exports, schedules, and broadcast events exist. Frontend live WebSocket consumption remains. |
| Notifications | Partial | In-app, email, SMS, preferences, settings, and delivery logs exist. Frontend live WebSocket consumption remains. |
| Tenant and subscription management | Partial | Plans, features, subscriptions, company lifecycle, limits, and usage metering exist. Stripe checkout and billing synchronization remain. |
| Custom module engine | Partial | Approved catalog, company installation lifecycle, compatibility checks, feature enforcement, and manifest conventions exist. Remote package execution is intentionally not automatic. |

## Cross-Cutting Requirements

| Requirement | Status | Notes |
| --- | --- | --- |
| Immutable CRUD audit trail | Complete | Model mutations, actor, company, request metadata, and changed values are recorded through the Platform module. |
| Company isolation | Partial | Enforced by domain policies and inactive-company middleware; expand isolation tests whenever a module is added. |
| Standard pagination/filter/sort | Partial | Shared query conventions are documented; legacy list endpoints still need incremental migration. |
| Tenant webhooks | Complete | Registration, signed delivery, delivery logs, retries, and automatic disabling are implemented. |
| Plan-aware API rate limits | Complete | Authenticated requests use the active plan rate limit and are metered. |
| GDPR export and erasure | Partial | Company lifecycle API includes export and erasure workflows; production retention/legal-hold policy must be configured. |
| Global search | Missing | Scout and Meilisearch are installed but a unified search endpoint and UI are still required. |
| Accessibility and full Arabic localization | Partial | Responsive RTL shell exists; a complete WCAG audit and domain-string translation pass remain. |
| CI/CD, containers, monitoring, backups, DR | Partial | Baseline CI, container, and operations documentation exist; production provider configuration and recovery exercises remain operational work. |

## External Integrations Still Requiring Credentials or Product Decisions

- Stripe Billing products, prices, checkout, customer portal, and webhook secret.
- Google and Microsoft OAuth application registrations.
- Mailchimp or SendGrid campaign sending credentials and consent policy.
- Pusher/Reverb production credentials and frontend Echo configuration.
- Sentry/Nightwatch production monitoring configuration.
- Backup destination, retention policy, encryption keys, and recovery ownership.
