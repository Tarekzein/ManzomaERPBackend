# Meta Integration Audit — 6 Aug 2026

Scope of this session: audit of the Meta integration module plus a system-wide
sweep, with the critical findings fixed. TikTok (Task 2), cross-module social
integration (Task 3), the remaining module-by-module audit (Task 4) and the
performance pass (Task 5) have **not** been started.

Severity is about business impact: **Critical** = silently loses data or leaks
credentials in normal operation.

---

## 1. Issues discovered

### Fixed this session

| # | Severity | Issue |
| --- | --- | --- |
| 1 | Critical | **Page access tokens were returned to the browser.** `GET /api/meta/assets/pages` requested `fields=id,name,access_token` and returned the payload verbatim; the frontend type even declared `access_token?: string`. A page token can post as the Page and read its leads. Nothing in the UI used it. |
| 2 | Critical | **The `meta-events` queue was never consumed.** Every Meta job is dispatched `->onQueue('meta-events')`, but the documented worker ran `queue:work` with no `--queue`, so it only drained `default`. In the documented deployment, lead webhooks, WhatsApp messages, conversion events and audience syncs enqueued and never ran. |
| 3 | High | **Super-admin calls silently targeted an arbitrary tenant.** `MetaIntegrationPolicy::companyId()` fell back to `Company::query()->value('id')` — the first company in the table. `DELETE /api/meta/connection` as a super admin disconnected that company's Meta account without naming it. |
| 4 | High | **Duplicate leads.** Ingest did check-then-insert on `meta_lead_id` with only a non-unique index. Meta re-delivers a lead until it gets a 2xx, so concurrent deliveries both passed the check and created two contacts. |
| 5 | High | **Unauthenticated DoS amplifier.** The webhook verify endpoint loaded *every* `meta_connections` row and decrypted each `webhook_verify_token` per request, because the column is encrypted (non-deterministic) and could not be queried. |
| 6 | Medium | **Webhooks sat behind the per-tenant rate limiter** (`throttle:erp-api`, as low as 60/min). Meta delivers in bursts and drops events that receive a 429. |
| 7 | Medium | **Webhook jobs had no retry policy or failure logging** — no `$tries`, `backoff`, `timeout` or `failed()`. A Graph blip discarded the lead with no trace. |
| 8 | Medium | **`backfill()` imported only the first Graph page** (~25 leads) and returned that as the total, so a form with 500 historical leads silently imported 25. |

### Open — these are the Meta rebuild backlog

| # | Severity | Issue |
| --- | --- | --- |
| 9 | Critical | **No token refresh whatsoever.** `access_token_expires_at` is stored and never acted on. Long-lived user tokens last ~60 days, then the integration goes dark with no warning and no notification. |
| 10 | High | **No permission validation.** `scopes` is written from `config('meta.scopes')` — what was *asked for*, never what Meta *granted*. A user who declines `leads_retrieval` looks connected and fails silently later. |
| 11 | High | **`disconnect()` neither revokes nor preserves.** It deletes the connection row, cascading away event logs, lead-form mappings and audience syncs (audit history), and never calls `DELETE /{user-id}/permissions`, so the token stays valid at Meta. |
| 12 | High | **No Instagram Business Account support** at all, though Task 1 requires it. |
| 13 | High | **No webhook subscription management.** Nothing calls `POST /{page-id}/subscribed_apps`; every page must be subscribed by hand in the Meta dashboard, and nothing detects an unsubscribed page. |
| 14 | Medium | **No reconnect flow.** Status stays `connected` until some unrelated call fails; there is no re-auth prompt, and `last_error` is the only signal. |
| 15 | Medium | **No lead attribution captured.** The leadgen node exposes `campaign_id`, `adset_id`, `ad_id`, `platform`; none are stored, so the Task 3 attribution work has nothing to build on. |
| 16 | Medium | **`MetaGraphClient` has no rate-limit or retry handling.** No backoff on 429/500, and `X-Business-Use-Case-Usage` is logged at debug level and never acted on. |
| 17 | Low | `health()` returns `success: true` with an error body — inconsistent with every other endpoint. |
| 18 | Low | `capi_batch_size` is configured but unused; conversion events are sent one per job. |

### System-wide sweep (first pass)

| # | Severity | Issue |
| --- | --- | --- |
| 19 | Medium | Pagination is thin: 14 `index` endpoints against 10 `paginate()` calls across modules. Unbounded list endpoints will degrade as tenants grow. |
| 20 | Low | `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` — the database is also the queue, cache and session store. Fine now; move to Redis before scaling workers. |
| 21 | — | Tenant scoping indexes are in good shape (74 index/unique declarations covering `company_id`); no missing-index findings. |

---

## 2. Fixes implemented

- **Page tokens stay server-side.** `MetaAssetService::pages()` no longer requests
  `access_token` and strips it defensively; a new `pageAccessToken()` fetches one
  server-side for the callers that genuinely need it (webhook subscription, lead
  reads). The frontend `MetaPage` type dropped the field.
- **Worker consumes the right queues.** `docker-compose.yml` now runs
  `--queue=meta-events,default` with `--max-time=3600`, and the operations runbook
  calls out that a default-only worker silently drops Meta work.
- **Super admins must name the company.** The policy takes `company_id` from the
  argument or the request and refuses to guess.
- **Lead ingest is race-safe.** Migration adds a unique
  `(company_id, meta_lead_id)` index (de-duplicating existing rows first) and the
  service handles the unique-violation path by returning the winner.
- **Verify-token lookup is a single indexed query** against a new
  `webhook_verify_token_hash` (SHA-256), kept in sync by a model hook and
  backfilled by the migration. The plaintext token stays encrypted at rest.
- **Webhooks are outside the tenant limiter**, with a dedicated
  `throttle:meta-webhooks` limiter at 600/min per IP.
- **Jobs retry and report.** Lead and WhatsApp webhook jobs get 5 tries,
  `[30, 120, 600, 1800]` backoff, a 60s timeout and a `failed()` hook that logs
  identifiers only — never lead field data or message bodies.
- **`backfill()` follows Graph cursor pagination** (100/page, capped at 50 pages).

---

## 3. Architectural improvements

- Introduced a queryable, non-reversible lookup key alongside an encrypted
  secret — the pattern to reuse wherever an encrypted column must be searched.
- Moved the trust boundary for page credentials to the server: the API surface
  now exposes page *identity* only.
- Made the tenant target explicit for privileged (super-admin) operations rather
  than implicit.

## 4. Performance optimisations

- Webhook verification went from *O(connections)* row loads + AES decryptions per
  unauthenticated request to one indexed lookup.
- Added `meta_connections(status, access_token_expires_at)` to support the
  token-refresh sweep that comes next.
- Lead backfill no longer silently truncates, and pages at 100 records.

## 5. Security improvements

1. Page access tokens no longer leave the server.
2. Privileged cross-tenant operations must name their target.
3. The public webhook endpoint can no longer be used to force mass decryption.
4. Job failure logs carry identifiers only, not personal data.
5. Webhook signature verification was reviewed and is correct: HMAC-SHA256 over
   the raw body with `hash_equals`, per-tenant app secret resolution, and it
   fails closed when no secret resolves.

## 6. Modules affected

`MetaIntegration` (services, policy, jobs, routes, model, migration), `CRM`
(`crm_contacts` unique index), `Platform` (rate limiter), frontend `types/meta`,
plus `docker-compose.yml` and the operations runbook.

## 7. Remaining recommendations

1. **Token lifecycle first** (#9) — everything else is moot when the token dies
   at day 60. Recommend System User tokens for server-to-server work: they do
   not expire, which removes the whole refresh problem for Business accounts.
2. Validate granted scopes against `/me/permissions` on connect and on health
   check (#10); surface a "reconnect required" state.
3. Make `disconnect()` revoke at Meta and soft-disconnect locally, preserving
   event history (#11).
4. Add Instagram Business Account discovery and webhook subscription management
   (#12, #13) — both are Task 1 requirements.
5. Capture attribution ids on lead ingest (#15) before starting Task 3.
6. Give `MetaGraphClient` retry/backoff and act on the usage headers (#16).

## 8. Third-party limitations

- Meta long-lived **user** tokens last ~60 days and there is no refresh token;
  they must be re-exchanged, or replaced with System User tokens.
- `leads_retrieval`, `ads_management`, `business_management`, `pages_*` and the
  `whatsapp_*` scopes require App Review and Advanced Access; without it the
  integration works only for app roles (admins/developers/testers).
- Instagram Business requires a linked Facebook Page plus `instagram_basic` and
  `instagram_manage_insights`.
- TikTok Marketing API requires app approval; sandbox advertisers return limited
  data and cannot serve real ads.

## 9. Testing performed

| Suite | Result |
| --- | --- |
| `MetaIntegrationModuleTest` (existing, 17 tests) | pass — no regressions from the fixes |
| `MetaIntegrationHardeningTest` (new, 6 tests) | pass — one per fixed finding |
| Full backend suite | 129 passed, 4 failed |
| Migration on the dev database | applied, including de-duplication and hash backfill |
| Pint | clean |

The 4 failures are pre-existing and unrelated (3 × `FinanceModuleTest`,
1 × `PlatformComplianceTest`); they fail identically on a clean checkout of
`HEAD`, verified earlier in a throwaway worktree.

Not tested: live Graph API calls. The fixes are covered by faked HTTP; live
verification against the Meta app is the first step of the rebuild session.

## 10. Configuration

Already required and present:

```
META_APP_ID, META_APP_SECRET          # or per-company via Settings → Meta
META_GRAPH_VERSION=v21.0
META_REDIRECT_URI
META_WEBHOOK_VERIFY_TOKEN
```

Needed for the work ahead:

```
# Meta rebuild
META_SYSTEM_USER_TOKEN                # recommended: non-expiring server-to-server token
META_TOKEN_REFRESH_LEAD_DAYS=7        # refresh window before expiry

# TikTok (Task 2 — Marketing/Ads API)
TIKTOK_APP_ID
TIKTOK_APP_SECRET
TIKTOK_REDIRECT_URI
TIKTOK_API_VERSION=v1.3
TIKTOK_WEBHOOK_SECRET
```

Operational: the queue worker **must** run
`php artisan queue:work --queue=meta-events,default`.
