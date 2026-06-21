# Operations Runbook

## Deployment

1. Provision MySQL 8+, Redis, object storage, SMTP/SES, and the application encryption key.
2. Configure production environment variables from `.env.example`.
3. Build the application image, run `php artisan migrate --force`, and deploy web, queue worker, and scheduler processes.
4. Run `php artisan config:cache`, `route:cache`, and `event:cache` during release.
5. Verify `/api/health`, queue processing, scheduled report delivery, notification delivery, and external webhooks.

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
