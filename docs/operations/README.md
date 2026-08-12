# Operations Runbook

## Deployment

1. Provision MySQL 8+, Redis, object storage, SMTP/SES, and the application encryption key.
2. Configure production environment variables from `.env.example`.
3. Build the application image, run `php artisan migrate --force`, and deploy web, queue worker, and scheduler processes.
   The worker must consume every queue in use: `php artisan queue:work --queue=meta-events,tiktok-events,default`.
   Jobs dispatched to `meta-events` (Meta lead webhooks, WhatsApp messages, conversion
   events, audience syncs) are silently never processed by a default-only worker.
4. Run `php artisan config:cache`, `route:cache`, and `event:cache` during release.
5. Verify `/api/health`, queue processing, scheduled report delivery, notification delivery, and external webhooks.

### Organization schema upgrade

The organization migration is additive, but the data backfill must run explicitly.
Do not use `DatabaseSeeder` as an upgrade command; it creates demo data and rewrites
catalog fixtures.

For the first deployment containing the organization schema:

1. Take a restorable database snapshot and verify it before starting.
2. Drain HTTP writes and stop old queue workers. Use a maintenance window for this
   release unless the old release has been changed to dual-write organization,
   membership, entitlement snapshot, and initiating-company fields.
3. From the new release, run `php artisan migrate --force`. The migrations add
   foreign keys and indexes to populated tables and may briefly acquire MySQL
   metadata locks, so monitor lock waits and database saturation.
4. With all old writers still stopped, run
   `php artisan organizations:backfill --chunk=100 --fail-on-issues`.
   Reduce the chunk size for unusually large tenants. The command is rerunnable and
   fills organization memberships, default workspaces, audit links, subscription
   entitlement snapshots, payment initiating companies, and organization billing
   suspension state.
5. Validate independently with
   `php artisan organizations:backfill --reconcile-only --fail-on-issues`.
   Do not resume traffic while any reconciliation count is non-zero. In particular,
   resolve ownerless organizations and organizations with multiple serving
   (`active`, `trialing`, or `past_due`) subscriptions manually; the command reports
   these ambiguous states but does not guess the intended owner or subscription.
6. Restart the new web and worker processes, leave maintenance mode, and verify the
   workspace bootstrap, subscription history, and one read-only request in each
   critical company module.

Existing plan rows and serving-subscription snapshots are migrated with
`max_companies = 1`; fresh catalog values are Basic `1`, Professional `5`, and
Enterprise `NULL` (unlimited). A super administrator must deliberately update
migrated plan limits through the subscription-plan administration API. Existing
customers keep their purchased snapshot until renewal/replacement or an intentional
snapshot migration. Do not rerun `DatabaseSeeder` to change the production catalog.

## Automatic Translation

- The Docker stack runs LibreTranslate internally with English and Arabic models only.
- Set `LIBRETRANSLATE_URL=http://libretranslate:5000` for containers, or `http://127.0.0.1:5000` when running Laravel directly and exposing the service locally.
- The first container start downloads language models and needs additional startup time, disk space, and memory.
- Verify the service with `GET /languages` before testing `POST /api/translations/batch`.
- Translation failures fall back to original ERP text and never block operational requests.

## Backups and Recovery

- Take encrypted daily database backups and object-storage versioned backups.
- Retain daily backups for 30 days and monthly backups for 12 months.
- Enable provider point-in-time recovery where available.
- Test a restore into an isolated environment at least quarterly.
- Document the production RTO/RPO with the hosting provider before launch.

## Monitoring

- Configure Laravel Nightwatch or Sentry for exceptions and request traces.
- Alert on queue failures, scheduler failures, failed webhooks, failed notifications, HTTP 5xx rates, and database saturation.
- Treat query durations over 200 ms as candidates for review.

## Security

- Terminate TLS at the load balancer or Nginx ingress.
- Rotate `APP_KEY` only through a planned re-encryption procedure.
- Store OAuth, Stripe, SMTP, Twilio, Pusher, and database credentials in a managed secret store.
- Run dependency and container-image vulnerability scans in CI.
