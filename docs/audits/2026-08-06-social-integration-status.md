# Social integration programme — completion status, 6 Aug 2026

This document supersedes the earlier session notes for the Meta rebuild,
TikTok integration, cross-module social work, and the module audit.

## Delivery status

| Area | State |
| --- | --- |
| Meta integration | Done in backend and frontend; live Graph verification remains. |
| TikTok integration | Done, including Lead Ads and CRM custom audiences; live TikTok verification remains. |
| Social inbox | Done for Facebook/Instagram comments and direct messages, plus WhatsApp messages. |
| Content publishing | Done for Facebook Page posts and Instagram image posts. |
| Social insights | Done using tenant-scoped local attribution data. |
| Module audit | Completed for Finance, HR, Inventory, Sales, Projects, and Reporting; findings are in the companion module audit. |

## TikTok custom audiences

Companies can create an audience from one of their CRM segments, select an
active advertiser, choose email or phone matching, and configure manual or
scheduled synchronization. Syncs are queued and expose `queued`, `processing`,
`synced`, `empty`, and `failed` states in the UI. Duplicate queue requests are
suppressed. Uploaded identifiers are normalized and SHA-256 hashed before they
leave the application, and deleting a CRM contact queues its removal from
previously synchronized audiences.

Tenant boundaries are enforced for connections, advertisers, segments, and
audiences. A missing advertiser falls back only to an active advertiser owned
by the same company.

Current provider limitation: subsequent scheduled syncs use TikTok's append
operation. Contacts deleted from CRM are removed, but a contact that remains in
CRM and merely stops matching a segment will not be removed until exact
membership reconciliation is added.

## Social support inbox

The unified inbox receives:

- Facebook Page comments and direct messages.
- Instagram comments and direct messages.
- WhatsApp inbound messages.

Agents can filter by platform/status, page through results, claim or update an
interaction, reply where the provider supports the interaction type, and
convert it to a CRM contact plus Project task. Conversion is idempotent and an
assignee must belong to the same company. Webhook redelivery is deduplicated.

TikTok messages are not included because the current TikTok Marketing/Lead Ads
integration does not provide an equivalent customer-support inbox webhook.

## Content publishing

The publishing UI posts text to a selected Facebook Page and publishes an
Instagram image by creating and then publishing a media container. Page and
linked Instagram account selection is tenant-scoped. Instagram publishing is
disabled when the selected Page has no linked Instagram Business account.

The Meta app for each company must be reconnected or reauthorized with the new
permissions (`pages_manage_posts`, `pages_read_user_content`,
`pages_manage_engagement`, `pages_messaging`, `instagram_basic`,
`instagram_content_publish`, `instagram_manage_comments`, and
`instagram_manage_messages`). App Review and Advanced Access may be required by
Meta.

## Verification

| Check | Result |
| --- | --- |
| Focused backend regression | 126 module and integration tests passed |
| Full backend suite | 214 passed, 1 pre-existing Platform compliance failure |
| Frontend tests | 39 passed |
| Frontend browser tests | 21 passed |
| Frontend production build | Passed, 2,980 modules transformed |
| PHP formatting | Pint passed on changed audited files |

The remaining full-suite failure expects a company admin to delegate
`audit.view`, while the current subscription-aware permission policy rejects
that permission. It predates this work and is outside the social/module audit
changes.

Provider calls in tests are faked. No live Meta or TikTok account was modified,
so OAuth approval, webhook delivery, publishing, and audience acceptance still
need a sandbox smoke test with each provider.

## Operations

The queue worker must consume the integration queues:

```bash
php artisan queue:work --queue=meta-events,tiktok-events,default
```

TikTok additionally requires each company to save its own app credentials and
complete OAuth. Meta likewise uses per-company app credentials; there is no
platform-wide credential fallback.
