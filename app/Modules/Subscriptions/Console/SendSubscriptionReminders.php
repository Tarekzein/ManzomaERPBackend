<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:send-reminders';

    protected $description = 'Send renewal, trial-ending and past-due billing reminders';

    public function handle(SubscriptionReminderService $reminders): int
    {
        $stats = $reminders->send();

        $this->info(sprintf(
            'Reminders sent — renewal: %d, trial: %d, past due: %d, errors: %d',
            $stats['renewal'] ?? 0,
            $stats['trial'] ?? 0,
            $stats['past_due'] ?? 0,
            $stats['errors'] ?? 0,
        ));

        return ($stats['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
