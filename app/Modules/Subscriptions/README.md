# Subscriptions Module

Owns the public plan catalog, super-admin plan and feature management, feature
assignment, and the company subscription lifecycle — including real Paymob
checkout, recurring renewals, dunning, reminders and cancellation.

## Paymob integration

`PAYMOB_MODE` selects the gateway bound to `PaymobGateway`:

| Mode | Binding | Use |
| --- | --- | --- |
| `mock` | `MockPaymobGateway` | local development and the test suite |
| anything else (`live`) | `PaymobCheckoutGateway` | real Paymob API |

The live gateway supports both Paymob flows and picks whichever is configured:

1. **Unified Checkout (preferred)** — `PAYMOB_SECRET_KEY`, `PAYMOB_PUBLIC_KEY`
   and `PAYMOB_INTEGRATION_ID`. A payment intention is created at
   `POST /v1/intention/` and the customer is sent to
   `https://accept.paymob.com/unifiedcheckout/?publicKey=…&clientSecret=…`.
2. **Legacy iframe** — `PAYMOB_API_KEY`, `PAYMOB_INTEGRATION_ID` and
   `PAYMOB_IFRAME_ID` (auth token → order → payment key → iframe URL).
3. **Saved-card renewals** — `PAYMOB_API_KEY` + `PAYMOB_MOTO_INTEGRATION_ID`.
   Renewals are charged with `POST /acceptance/payments/pay` using the stored
   card token. Without a MOTO integration the renewal falls back to sending a
   checkout link, which still works but needs the customer to act.

Register these URLs in the Paymob dashboard (Developers → Payment Integrations):

- Transaction processed callback: `POST {APP_URL}/api/payments/paymob/callback`
- Transaction response callback: `GET {APP_URL}/api/payments/paymob/callback`
  (redirects the browser to `FRONTEND_URL` + `SUBSCRIPTION_CHECKOUT_RETURN_PATH`)

Callbacks are only trusted after their HMAC-SHA512 signature is verified against
`PAYMOB_HMAC_SECRET` using Paymob's documented field order
(`Support\PaymobSignature`). The webhook accepts both `TRANSACTION` and `TOKEN`
payloads; a `TOKEN` callback stores the card on the subscription so the next
renewal can be charged automatically.

## Free trial

A plan with `trial_enabled` and `trial_days > 0` starts a trial instead of a
checkout, **once per company** (`trial_ends_at` on any past subscription marks
the trial as used):

- **At registration** — the workspace activates immediately, the admin is
  signed in, and a zero-amount payment records the trial. No card is asked for.
- **From inside the app** — `POST /subscriptions/subscribe` starts the trial and
  returns the subscription; a second attempt returns a checkout session instead.

The trial period *is* the billing period (`current_period_ends_at` =
`trial_ends_at`), so the renewal command converts it on the day it ends: the
saved card is charged if there is one, otherwise a checkout link goes out and
the subscription follows the normal past-due → grace → expired path.

## Paying once, and only once

Paymob rejects a `special_reference` it has already seen
(`400 An Order with ref X already exists`), so each checkout attempt allocates
its own — `{reference}` first, then `{reference}.2`, `.3` — stored in
`provider_reference` and used to match callbacks back to the invoice.

The rules that keep a customer from paying twice:

- **One live session per invoice.** `openCheckout()` hands back the existing
  URL while it is unexpired, so repeated "pay" clicks reuse the same Paymob
  order. Only a missing or expired session creates a new one.
- **Paid invoices are closed.** A settled payment is never re-opened;
  `POST /payments/{reference}/checkout` answers `409`.
- **One open invoice per plan change.** Requesting the same plan and cycle
  again returns the pending payment instead of creating a second one.
- **One invoice per billing period** for renewals (`billing_period_key`), with
  declined attempts reopened rather than duplicated.
- **Stale links are superseded.** When a payment settles, other pending
  registration/upgrade invoices for that company are closed. If one is paid
  anyway, it is marked succeeded, flagged `duplicate_payment` and logged for a
  refund — it never starts a second subscription.
- **Repeat webhooks are no-ops.** `markSucceeded()` locks the row and returns
  early; a *different* transaction id on a settled invoice is recorded under
  `metadata.duplicate_transactions` and logged rather than silently dropped.

## Subscription lifecycle

Statuses (`Enums\SubscriptionStatus`): `trialing`, `active`, `past_due`,
`cancelled`, `expired`. `trialing`, `active` and `past_due` all grant access.

```
registration ──▶ pending payment ──(webhook succeeded)──▶ active
trial ──────────────────────────────────────────────────▶ active | past_due
active ──(period ends)──▶ charge saved card ──▶ active
                       └▶ no card / declined ──▶ past_due (grace) ──▶ expired
active ──(cancel)──────▶ cancel_at_period_end ──▶ expired at period end
active ──(cancel now)──▶ cancelled + company suspended
```

`subscriptions:process-renewals` (hourly) does the work:

- charges the saved card **on** the renewal date, never before it;
- retries declines `subscriptions.retry.max_attempts` times, spaced by
  `retry.interval_hours`, then hands the customer a checkout link for the same
  invoice (one payment row per billing period, keyed by `billing_period_key`);
- issues a checkout link inside `renewal_window_hours` when there is no saved
  card, and moves the subscription to `past_due` once the period has ended;
- expires whatever is still unpaid when `grace_days` run out, and closes out
  subscriptions that were cancelled at period end.

`subscriptions:send-reminders` (daily 09:00) sends renewal reminders at
`reminders.renewal_days`, trial-ending reminders at `reminders.trial_days`, and
a daily past-due nudge. Every reminder is de-duplicated per subscription and
milestone in `reminders_sent`.

A company suspended for non-payment is flagged with `settings.billing.suspended_at`.
Its admins can still log in and reach the billing endpoints (and nothing else)
so they can pay and be reinstated automatically.

## API

| Method | Path | Notes |
| --- | --- | --- |
| `POST` | `/api/subscriptions/subscribe` | trials and free plans activate (201); paid plans return a checkout session (202) |
| `POST` | `/api/subscriptions/checkout` | start a checkout for a plan/cycle change (`start_trial` opts into an unused trial) |
| `POST` | `/api/subscriptions/renew` | pay the next period by hand |
| `POST` | `/api/subscriptions/cancel` | `immediately` (default `false`), `reason` |
| `POST` | `/api/subscriptions/resume` | undo a scheduled cancellation |
| `POST` | `/api/subscriptions/auto-renew` | `auto_renew` toggle |
| `DELETE` | `/api/subscriptions/payment-method` | forget the saved card |
| `GET` | `/api/subscriptions/payments` | billing history |
| `GET` | `/api/subscriptions/payments/{reference}` | poll an in-app checkout |
| `GET` | `/api/payments/{reference}/status` | poll a registration checkout (needs `registration_token`) |
| `POST` | `/api/payments/{reference}/checkout` | re-open an expired or failed checkout session |
| `POST` | `/api/payments/{reference}/session` | exchange a paid registration checkout for an API token |
| `POST` | `/api/payments/{reference}/mock-result` | mock mode only, 404 when Paymob is live |

## Configuration

`config/services.php` → `paymob` holds credentials and endpoints;
`config/subscriptions.php` holds the lifecycle policy (renewal window, grace
days, retry policy, reminder milestones, checkout TTL and return URL).
