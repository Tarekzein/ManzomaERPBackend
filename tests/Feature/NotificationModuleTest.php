<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Models\NotificationDeliveryLog;
use App\Modules\Notifications\Services\NotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_read_preferences_announcements_and_delivery_logs_work(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $feed = $this->getJson('/api/notifications')->assertOk()->assertJsonStructure(['data' => ['data'], 'meta' => ['unread_count']])->json('data.data');
        $this->assertNotEmpty($feed);
        $this->getJson('/api/notifications/unread-count')->assertOk();
        $this->postJson("/api/notifications/{$feed[0]['id']}/read")->assertOk()->assertJsonPath('data.read_at', fn ($value) => $value !== null);
        $this->postJson('/api/notifications/read-all')->assertOk();

        $preferences = $this->getJson('/api/notifications/preferences')->assertOk()->json('data');
        $this->putJson('/api/notifications/preferences', ['preferences' => [[
            'event_type' => 'system.announcement', 'in_app' => true, 'email' => false, 'sms' => false,
        ]]])->assertOk()->assertJsonFragment(['event_type' => 'system.announcement', 'email' => false]);

        $this->postJson('/api/notifications/announce', [
            'title' => 'Maintenance window', 'message' => 'The ERP will be maintained tonight.', 'severity' => 'warning',
        ])->assertOk();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
        $this->getJson('/api/notifications/deliveries')->assertOk();
        $this->assertDatabaseHas('notification_delivery_logs', ['user_id' => $admin->id, 'event_type' => 'system.announcement', 'channel' => 'in_app']);
        $this->assertNotEmpty($preferences);
    }

    public function test_email_and_tenant_twilio_sms_follow_user_preferences(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $admin->employee()->updateOrCreate(
            ['company_id' => $admin->company_id],
            ['employee_number' => 'NOTIF-ADMIN', 'name' => $admin->name, 'email' => $admin->email, 'phone' => '+201000000000', 'hire_date' => today(), 'status' => 'active', 'base_salary' => 0, 'currency' => 'EGP']
        );
        $settings = $admin->company->settings ?? [];
        $settings['notifications'] = ['email' => ['enabled' => true, 'mailer' => 'smtp'], 'sms' => ['enabled' => true], 'twilio' => ['sid' => 'AC123', 'token' => 'secret', 'from' => '+15550000000']];
        $admin->company->update(['settings' => $settings]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/notifications/preferences', ['preferences' => [[
            'event_type' => 'inventory.reorder', 'in_app' => true, 'email' => true, 'sms' => true,
        ]]])->assertOk();
        app(NotificationService::class)->send($admin->fresh(), 'inventory.reorder', 'Critical stock', 'Stock is below the reorder point.', severity: 'critical');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Messages.json'));
        $this->assertDatabaseHas('notification_delivery_logs', ['user_id' => $admin->id, 'channel' => 'sms', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_delivery_logs', ['user_id' => $admin->id, 'channel' => 'mail', 'status' => 'sent']);
    }

    public function test_users_cannot_read_another_users_notifications(): void
    {
        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $other = User::factory()->create(['company_id' => $admin->company_id]);
        app(NotificationService::class)->send($other, 'system.announcement', 'Private', 'Only for the other user.');
        Sanctum::actingAs($admin);
        $notification = $other->notifications()->firstOrFail();

        $this->postJson("/api/notifications/{$notification->id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$notification->id}")->assertNotFound();
    }
}
