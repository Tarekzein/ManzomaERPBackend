# Meta (Facebook) Integration

Per-tenant integration with Meta Business: Conversions API (server-side event tracking), Facebook/Instagram Lead Ads → CRM sync via webhooks, CRM segment → Meta Custom Audience sync, and WhatsApp Business Cloud API (template messages to CRM contacts + inbound messages logged as CRM activities). Messenger is out of scope.

## Setup

There is **no platform-wide Meta App**. Each tenant brings their own Meta App ID/App Secret — there is nothing to configure in `.env` for this to work per company (see "Per-tenant Meta Apps" below).

1. Set in `.env` (all optional — see below):
   ```
   META_GRAPH_VERSION=v21.0
   META_REDIRECT_URI=https://your-frontend-host/auth/facebook/callback
   META_WEBHOOK_VERIFY_TOKEN=some-random-string
   ```
   `META_REDIRECT_URI` should match whatever frontend host serves `/auth/facebook/callback`. `META_WEBHOOK_VERIFY_TOKEN` is an optional *shared* fallback verify token for the leadgen webhook handshake (see below) — tenants can also use their own auto-generated per-connection token instead.
2. Run `php artisan migrate` and re-seed permissions/plan features: `php artisan db:seed --class=RolesAndPermissionsSeeder` and `--class=SubscriptionSeeder` (adds the `meta.*` permissions and the `integrations.meta` subscription feature, included on the `professional` and `enterprise` plans).
3. Each tenant, from **Settings → Meta Integration**:
   a. Creates their own Meta App at developers.facebook.com with **Facebook Login for Business** and **Marketing API** products, and notes its App ID/App Secret.
   b. **Business-type apps (the default for new apps with business products)**: classic OAuth `scope=` lists are rejected with "Invalid Scopes" — Meta requires Facebook Login for Business. In the app dashboard, open *Facebook Login for Business → Configurations*, create a configuration granting the needed permissions (ads_management, business_management, leads_retrieval, pages_show_list, pages_read_engagement, pages_manage_metadata, and the whatsapp_business_* permissions if using WhatsApp), and copy the **Configuration ID**.
   c. Pastes App ID/App Secret (and the Configuration ID for Business apps — leave empty for classic Consumer apps, which use the scope-list flow) into the "Meta App credentials" form. This generates a per-connection webhook verify token they can use in step (e).
   d. Clicks "Connect with Facebook" (OAuth, using their own App) — or uses the manual-entry form to paste a System User access token directly instead of going through OAuth.
   e. If they want Lead Ads sync, they configure a `leadgen` webhook subscription **in their own Meta App's dashboard**, pointing at `https://your-backend-host/api/meta/webhooks/leadgen`, using either `META_WEBHOOK_VERIFY_TOKEN` or their own connection's generated verify token (shown in Settings) as the verify token. Note: the OAuth redirect URI (`META_REDIRECT_URI`) must also be added to the app's *Facebook Login → Valid OAuth Redirect URIs*.

## WhatsApp Business

Each company can also connect its own WhatsApp Business account, from the **WhatsApp** tab in Settings → Meta Integration:

1. The tenant's Meta App must have the **WhatsApp** product added; the OAuth scope list already requests `whatsapp_business_management` and `whatsapp_business_messaging`.
2. After connecting and selecting a Meta Business in the Connection tab, the WhatsApp tab lists the business's WhatsApp Business accounts (WABAs) and their phone numbers; the tenant enables WhatsApp and picks one of each (stored on `meta_connections`: `whatsapp_enabled`, `whatsapp_business_account_id`, `whatsapp_phone_number_id`).
3. **Outbound**: `POST /api/meta/whatsapp/send` sends an approved template message (e.g. `hello_world`) to a CRM contact (`contact_id`) or a raw phone (`to_phone`); when sent to a contact it is logged as a `crm_activities` row with `type=whatsapp`.
4. **Inbound**: the tenant subscribes their app's **WhatsApp → messages** webhook to the same URL as leadgen (`/api/meta/webhooks/leadgen` handles both topics; the payload's `field` distinguishes them). Incoming messages are matched to the owning company via the receiving `phone_number_id`, deduplicated by WhatsApp message id, matched to an existing CRM contact by phone (or a new lead with `source=whatsapp` is created — which also fires the CAPI `Lead` event if mapped), and logged as a `whatsapp` CRM activity. Signature verification resolves the company's own App Secret from `phone_number_id`, same as the per-tenant leadgen path.

## Per-tenant Meta Apps

Because every company can use a **different** Meta App, both the webhook handshake and the webhook signature verification had to become multi-app aware:

- **`hub.challenge` handshake** (`GET /meta/webhooks/leadgen`): accepts either the shared `META_WEBHOOK_VERIFY_TOKEN` or any company's own `meta_connections.webhook_verify_token` (auto-generated when they save their App credentials).
- **`X-Hub-Signature-256` verification** (`POST /meta/webhooks/leadgen`): the payload's `page_id` is resolved (unsigned, read-only JSON parse) to the owning company's `meta_lead_form_mappings` → `meta_connections.app_secret` *before* verifying the HMAC signature with that specific company's App Secret, falling back to a global `config('meta.app_secret')` if one happens to be set. A request is only accepted once a company has mapped that `page_id` — i.e. Lead Ads webhooks only verify successfully after the tenant has both connected their app and created a lead-form mapping for that page.
- `MetaGraphClient`/`MetaOAuthService` prefer a connection's own `app_id`/`app_secret` for all Graph API calls (OAuth token exchange, `appsecret_proof`), falling back to the global config only if a company hasn't set their own (mainly useful for local/dev testing with a single shared app).

## New ops requirement: a queue worker

This module introduces the first real queued jobs (`ShouldQueue`) in this codebase — CAPI event delivery and audience sync run on the `meta-events` queue name, using the existing `database` queue connection. **A persistent worker process must run in every environment** (dev, staging, production):

```
php artisan queue:work database --queue=meta-events --tries=5 --backoff=30,120,600
```

Supervise this with Supervisor/systemd/your platform's process manager. Without a running worker, `meta_event_logs` rows and audience syncs will sit in `pending` indefinitely (the scheduled `meta:retry-events` / `meta:sync-audiences` commands only *dispatch* jobs onto the queue — they don't process them synchronously).

## Compliance

- `crm_contacts.meta_consent` is a tri-state flag (`null` = not asked, `true`/`false` = explicit). When a connection's `require_consent` flag is enabled, `MetaConversionService` will not send CAPI events for contacts without `meta_consent === true`.
- Limited Data Use (LDU/CCPA): enable `ldu_enabled` on the connection and set `ldu_country`/`ldu_state` (Meta's documented numeric codes) — outgoing CAPI events will include `data_processing_options`.
- Deleting a CRM contact fires `CrmContactDeleted`, which removes that contact's hashed identifiers from every synced Custom Audience.

## Known scope limitations (documented, not bugs)

- CAPI events for `invoice_paid` (Finance module) do not carry `meta_fbc`/`meta_fbp`/consent linkage, since `FinanceContact` isn't tied to a `CRMContact` or its consent flag in this pass — match quality for Purchase events will be lower than for CRM-sourced events.
- `fbc`/`fbp` browser cookies are only populated on `crm_contacts` if a future public web form captures and forwards them; Lead Ads-sourced leads never have them (expected, per Meta's own docs).
- The CAPI delivery job sends one event per Graph API call rather than true 1000-event batching; the scheduled `meta:retry-events` sweep re-dispatches eligible retries individually rather than batching them. This favors simplicity/latency for the common case; true request-level batching is a reasonable follow-up if event volume grows.
