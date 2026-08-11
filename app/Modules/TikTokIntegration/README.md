# TikTok Integration Module

TikTok Marketing API (v1.3) integration, built to mirror the Meta module so the
two behave the same way operationally.

## What it does

| Capability | Notes |
| --- | --- |
| OAuth connection | Per-company TikTok for Business app, same model as Meta |
| Token lifecycle | **Refresh tokens exist** (unlike Meta), so renewal is unattended |
| Advertiser sync | Authorised ad accounts, deactivated rather than deleted when revoked |
| Campaign reporting | `report/integrated/get` for spend, impressions, clicks, conversions |
| Conversion events | Events API 2.0, driven by the same CRM/finance triggers as Meta |
| Multi-company | Every query is scoped by `company_id`; policy enforces it |
| Notifications | Expiring/expired token, missing permissions, sync failures |

## Two API quirks that shape the code

1. **Failures arrive inside HTTP 200.** The real status is `code` in the body
   (`0` = success). `TikTokClient` treats any non-zero `code` as an error, which
   is why it cannot use a conventional `$response->failed()` check.
2. **Refresh tokens rotate.** Each refresh returns a *new* refresh token; storing
   the old one breaks the next renewal. `TikTokTokenService::refresh()` persists
   whatever comes back.

## Token lifecycle

`tiktok:maintain-connections` runs daily at 04:45:

- refreshes tokens expiring within `TIKTOK_TOKEN_REFRESH_LEAD_DAYS` (default 7);
- refreshes expired tokens too, which Meta cannot do;
- marks the connection `expired` and notifies when the refresh token has also
  lapsed — the only case needing a human;
- verifies liveness for connections with no reported expiry.

## Conversion events

`TikTokEventService::recordEvent()` listens to the same events the Meta module
uses (`CrmLeadCreated`, `CrmOpportunityWon`, `InvoicePaid`), so one business
action can feed both platforms. Events require `events_enabled` and a
`pixel_code` on the connection, are queued to `tiktok-events`, and carry
SHA-256-hashed identifiers (email, phone, external id) normalised exactly as
Meta requires — the hashing service is shared.

Failed deliveries are retried through the log with the configured backoff
(`tiktok:retry-events`, every five minutes) and dead-lettered as `failed` when a
permanent error or the attempt cap is hit.

## Per-company apps

As with Meta, **there is no platform-wide TikTok app**. `TikTokConnection`
exposes `appId()` / `appSecret()`, which throw `MissingAppCredentialsException`
instead of falling back to config. Each company saves its own App ID and secret
under Company profile → TikTok Integration, and `GET /api/tiktok/setup` returns
the redirect URI and required scopes for them to paste into their own app.
Lead Ads are collected through scheduled export tasks; no TikTok webhook URL is
advertised until a receiver is implemented.

## Environment

```
TIKTOK_REDIRECT_URI=https://your-app/auth/tiktok/callback
TIKTOK_API_VERSION=v1.3
TIKTOK_BASE_URL=https://business-api.tiktok.com/open_api
TIKTOK_AUTH_URL=https://business-api.tiktok.com/portal/auth
TIKTOK_WEBHOOK_SECRET=
TIKTOK_REQUEST_RETRIES=3
TIKTOK_TOKEN_REFRESH_LEAD_DAYS=7
```

The queue worker must consume `tiktok-events`:
`php artisan queue:work --queue=meta-events,tiktok-events,default`.

## Lead Ads ingestion

TikTok does not push leads over a webhook. You request an export, TikTok builds
it asynchronously, and you poll until a download is ready — so ingestion is a
two-phase job driven by `tiktok:sync-leads` (every ten minutes):

1. **Request** — `page/lead/task/create` for the window since `synced_through`
   (first run reaches back a week). The task id is stored on the mapping.
2. **Collect** — `page/lead/task/get`; `PROCESSING` simply waits for the next
   pass. On `SUCCESS` the rows are read either inline or from the signed
   `download_url`, which serves JSON for small exports and CSV for large ones —
   both are parsed.

A task that never completes is abandoned after an hour and re-requested, so one
stuck export cannot block a mapping forever. Leads are de-duplicated on
`(company_id, tiktok_lead_id)` by unique index, carry campaign/adgroup/ad
attribution (first-touch), and fire the same `CrmLeadCreated` event as Meta —
so one TikTok lead can also trigger a Meta conversion event.

Map a form through `POST /api/tiktok/lead-forms`; `GET
/api/tiktok/lead-forms/available` lists the advertiser's instant pages.

## Not built yet

- **Custom audiences** (`dmp/custom_audience/*`) — the Meta equivalent exists and
  the CRM segment plumbing is reusable. TikTok's audience upload is a multipart
  file flow rather than a JSON batch, so it is not a direct port.
- **Webhooks** — TikTok's webhook coverage is narrower than Meta's; advertiser
  and lead events are the useful ones.

## Approval requirements

Nothing works in production until the TikTok for Business app is approved for
the scopes it requests. Sandbox advertisers return limited data and cannot serve
real ads, so campaign reporting numbers will be empty until a live advertiser is
authorised.
