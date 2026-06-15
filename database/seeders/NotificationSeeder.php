<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(NotificationService $notifications): void
    {
        User::query()->each(function (User $user) use ($notifications) {
            foreach ($notifications->eventTypes() as $eventType => $event) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'event_type' => $eventType],
                    ['in_app' => true, 'email' => true, 'sms' => $event['critical']]
                );
            }
        });

        $admin = User::where('email', 'company.admin@example.com')->first();
        if ($admin && ! $admin->notifications()->exists()) {
            $notifications->send($admin, 'system.announcement', 'Welcome to Notifications', 'Your notification center is ready. Configure channel preferences at any time.', actionUrl: '/notifications', severity: 'success');
        }
    }
}
