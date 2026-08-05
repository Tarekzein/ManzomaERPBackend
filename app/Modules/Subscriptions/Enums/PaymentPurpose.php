<?php

namespace App\Modules\Subscriptions\Enums;

enum PaymentPurpose: string
{
    /** First payment taken during company registration. */
    case Registration = 'registration';

    /** Recurring charge that extends the current subscription period. */
    case Renewal = 'renewal';

    /** Plan or billing-cycle change requested from inside the app. */
    case Upgrade = 'upgrade';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
