<?php

namespace App\Modules\Subscriptions\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BillingPeriod
{
    public const MONTHLY = 'monthly';

    public const ANNUAL = 'annual';

    /**
     * End of the period that starts at $from. Month/year arithmetic never
     * overflows, so a period starting on the 31st renews on the 28th/30th.
     */
    public static function end(string $billingCycle, ?CarbonInterface $from = null): Carbon
    {
        $start = $from ? Carbon::instance($from->toDateTime()) : Carbon::now();

        return self::isAnnual($billingCycle)
            ? $start->copy()->addYearNoOverflow()
            : $start->copy()->addMonthNoOverflow();
    }

    /**
     * Next period end for a renewal. Chaining from the previous period end
     * keeps the billing date stable; if the subscription lapsed for longer
     * than a full period we bill from now instead of stacking dead time.
     */
    public static function nextEnd(string $billingCycle, ?CarbonInterface $previousEnd = null): Carbon
    {
        $anchor = $previousEnd ? Carbon::instance($previousEnd->toDateTime()) : Carbon::now();

        if ($anchor->isPast()) {
            $candidate = self::end($billingCycle, $anchor);

            return $candidate->isPast() ? self::end($billingCycle, Carbon::now()) : $candidate;
        }

        return self::end($billingCycle, $anchor);
    }

    public static function isAnnual(string $billingCycle): bool
    {
        return in_array($billingCycle, [self::ANNUAL, 'annually', 'yearly'], true);
    }

    public static function label(string $billingCycle): string
    {
        return self::isAnnual($billingCycle) ? 'year' : 'month';
    }
}
