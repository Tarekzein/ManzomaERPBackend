<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing lifecycle
    |--------------------------------------------------------------------------
    |
    | Drives the renewal, dunning and expiry behaviour applied by the
    | `subscriptions:process-renewals` and `subscriptions:send-reminders`
    | commands.
    |
    */

    // How long before the period ends we start preparing the renewal charge
    // or checkout link, so the customer has time to act.
    'renewal_window_hours' => (int) env('SUBSCRIPTION_RENEWAL_WINDOW_HOURS', 72),

    // Days of continued access after a renewal fails or is left unpaid.
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 5),

    'retry' => [
        // Saved-card charge attempts per renewal before the subscription is
        // left to the customer to settle manually.
        'max_attempts' => (int) env('SUBSCRIPTION_RETRY_ATTEMPTS', 3),
        'interval_hours' => (int) env('SUBSCRIPTION_RETRY_INTERVAL_HOURS', 24),
    ],

    'reminders' => [
        // Days before the renewal date an upcoming-charge reminder is sent.
        'renewal_days' => [7, 3, 1],
        // Days before a trial ends that a conversion reminder is sent.
        'trial_days' => [3, 1],
        // Send a daily nudge while a subscription sits in the grace window.
        'past_due_daily' => true,
    ],

    // Checkout sessions older than this are abandoned so a customer can start
    // a fresh one instead of reusing an expired Paymob intention.
    'checkout_session_ttl_minutes' => (int) env('SUBSCRIPTION_CHECKOUT_TTL_MINUTES', 60),

    'checkout' => [
        // Where Paymob sends the customer back to after payment.
        'app_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
        'return_path' => env('SUBSCRIPTION_CHECKOUT_RETURN_PATH', '/checkout/{reference}'),
    ],
];
