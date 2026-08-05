<?php

namespace App\Modules\Subscriptions\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Statuses that still grant access to the product. `past_due` is included
     * because the company keeps working through the grace window.
     */
    public static function servingValues(): array
    {
        return [self::Active->value, self::Trialing->value, self::PastDue->value];
    }

    /** Statuses eligible for the renewal cycle. */
    public static function renewableValues(): array
    {
        return [self::Active->value, self::Trialing->value, self::PastDue->value];
    }

    public static function terminatedValues(): array
    {
        return [self::Cancelled->value, self::Expired->value];
    }
}
