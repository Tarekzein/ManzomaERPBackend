<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Jobs\SyncTikTokAudienceJob;
use App\Modules\TikTokIntegration\Models\TikTokAudienceSync;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Services\TikTokAudienceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TikTokAudienceTest extends TestCase
{
    use RefreshDatabase;

    /** Segments filter on region, which keeps seeded contacts out of the audience. */
    private const REGION = 'Audience Test Region';

    /** First run uploads the file, then creates the audience from it. */
    public function test_the_first_sync_uploads_hashed_identifiers_and_creates_the_audience(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin);
        $this->contacts($admin, ['buyer1@example.com', 'buyer2@example.com']);

        Http::fake([
            '*custom_audience/file/upload*' => Http::response(['code' => 0, 'data' => ['file_path' => 'path/to/file']]),
            '*custom_audience/create*' => Http::response(['code' => 0, 'data' => ['custom_audience_id' => 'aud-1']]),
        ]);

        app(TikTokAudienceService::class)->sync($sync);

        $sync->refresh();
        $this->assertSame('synced', $sync->status);
        $this->assertSame('aud-1', $sync->tiktok_audience_id);
        $this->assertSame(2, $sync->approximate_count);
        $this->assertNotNull($sync->last_synced_at);

        // Identifiers must leave as SHA-256, never as raw email addresses.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'file/upload')) {
                return true;
            }

            $body = $request->body();

            return ! str_contains($body, 'buyer1@example.com')
                && str_contains($body, hash('sha256', 'buyer1@example.com'));
        });
    }

    public function test_a_later_sync_appends_to_the_existing_audience(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin, ['tiktok_audience_id' => 'aud-existing']);
        $this->contacts($admin, ['buyer3@example.com']);

        Http::fake([
            '*custom_audience/file/upload*' => Http::response(['code' => 0, 'data' => ['file_path' => 'path/2']]),
            '*custom_audience/update*' => Http::response(['code' => 0, 'data' => []]),
            '*custom_audience/create*' => Http::response(['code' => 0, 'data' => ['custom_audience_id' => 'should-not-be-called']]),
        ]);

        app(TikTokAudienceService::class)->sync($sync);

        $this->assertSame('aud-existing', $sync->fresh()->tiktok_audience_id);
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'custom_audience/create'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'custom_audience/update')
            && $request['action'] === 'APPEND');
    }

    /** Phone audiences hash the normalised number, not the email. */
    public function test_a_phone_audience_uploads_hashed_phone_numbers(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin, ['calculate_type' => 'PHONE_SHA256']);
        CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Phone Buyer',
            'phone' => '+20 100 123 4567',
            'type' => 'customer',
            'region' => self::REGION,
            'status' => 'new',
            'currency' => 'EGP',
        ]);

        Http::fake([
            '*file/upload*' => Http::response(['code' => 0, 'data' => ['file_path' => 'p']]),
            '*custom_audience/create*' => Http::response(['code' => 0, 'data' => ['custom_audience_id' => 'aud-phone']]),
        ]);

        app(TikTokAudienceService::class)->sync($sync);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'file/upload')) {
                return true;
            }

            return str_contains($request->body(), hash('sha256', '201001234567'));
        });
    }

    public function test_an_empty_segment_is_recorded_without_calling_tiktok(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin, [], ['regions' => ['Nowhere']]);

        Http::fake();

        app(TikTokAudienceService::class)->sync($sync);

        $sync->refresh();
        $this->assertSame('empty', $sync->status);
        $this->assertSame(0, $sync->approximate_count);
        Http::assertNothingSent();
    }

    /** An upload failure arrives inside a 200 body like every other TikTok error. */
    public function test_an_upload_failure_marks_the_sync_failed(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin);
        $this->contacts($admin, ['buyer4@example.com']);

        Http::fake(['*file/upload*' => Http::response([
            'code' => 40002,
            'message' => 'Advertiser is not authorized for DMP',
        ], 200)]);

        $this->expectException(TikTokApiException::class);

        try {
            app(TikTokAudienceService::class)->sync($sync);
        } finally {
            $sync->refresh();
            $this->assertSame('failed', $sync->status);
            $this->assertStringContainsString('not authorized', (string) $sync->last_error);
        }
    }

    /** Deleting a contact must pull them out of every audience they were pushed to. */
    public function test_deleting_a_contact_removes_them_from_the_audience(): void
    {
        $admin = $this->admin();
        $this->sync($admin, ['tiktok_audience_id' => 'aud-remove']);
        $contact = $this->contacts($admin, ['gone@example.com'])->first();

        Http::fake([
            '*file/upload*' => Http::response(['code' => 0, 'data' => ['file_path' => 'p-remove']]),
            '*custom_audience/update*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        app(TikTokAudienceService::class)->removeContact($contact);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'custom_audience/update')
            && $request['action'] === 'REMOVE'
            && $request['custom_audience_id'] === 'aud-remove');
    }

    public function test_audiences_are_scoped_to_the_owning_company(): void
    {
        $admin = $this->admin();
        $this->sync($admin);

        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival-tt@example.com', 'status' => 'active']);
        $otherConnection = TikTokConnection::create([
            'company_id' => $other->id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'tt-app-b',
            'app_secret' => 'tt-secret-b',
            'access_token' => 'tt-token-b',
        ]);
        $otherSegment = CRMSegment::create([
            'company_id' => $other->id,
            'name' => 'Rival buyers',
            'criteria' => ['regions' => [self::REGION]],
        ]);
        $theirs = TikTokAudienceSync::create([
            'company_id' => $other->id,
            'tiktok_connection_id' => $otherConnection->id,
            'crm_segment_id' => $otherSegment->id,
            'advertiser_id' => 'adv-b',
            'audience_name' => 'Rival audience',
            'calculate_type' => 'EMAIL_SHA256',
            'status' => 'pending',
        ]);

        $mine = $this->getJson('/api/tiktok/audiences')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertNotSame($theirs->id, $mine[0]['id']);

        $this->postJson("/api/tiktok/audiences/{$theirs->id}/sync")->assertForbidden();
        $this->deleteJson("/api/tiktok/audiences/{$theirs->id}")->assertForbidden();
    }

    public function test_saving_an_audience_uses_the_default_advertiser_and_rejects_foreign_segments(): void
    {
        $admin = $this->admin();
        TikTokConnection::create([
            'company_id' => $admin->company_id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'tt-app-a',
            'app_secret' => 'tt-secret-a',
            'access_token' => 'tt-token-a',
            'default_advertiser_id' => 'adv-default',
        ]);
        $mine = CRMSegment::create([
            'company_id' => $admin->company_id,
            'name' => 'My buyers',
            'criteria' => [],
        ]);

        $this->postJson('/api/tiktok/audiences', [
            'crm_segment_id' => $mine->id,
            'audience_name' => 'My audience',
            'advertiser_id' => '',
        ])->assertCreated()->assertJsonPath('data.advertiser_id', 'adv-default');

        $other = Company::create(['name' => 'Rival Co', 'email' => 'rival-segment@example.com', 'status' => 'active']);
        $foreign = CRMSegment::create(['company_id' => $other->id, 'name' => 'Rival buyers', 'criteria' => []]);

        $this->postJson('/api/tiktok/audiences', [
            'crm_segment_id' => $foreign->id,
            'audience_name' => 'Foreign audience',
        ])->assertUnprocessable();
    }

    public function test_sync_requests_are_queued_once_and_expose_the_queue_state(): void
    {
        $admin = $this->admin();
        $sync = $this->sync($admin);
        Queue::fake();

        $this->postJson("/api/tiktok/audiences/{$sync->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued');
        $this->postJson("/api/tiktok/audiences/{$sync->id}/sync")->assertOk();

        Queue::assertPushed(SyncTikTokAudienceJob::class, 1);
    }

    /** @param array<int, string> $emails */
    private function contacts(User $admin, array $emails)
    {
        return collect($emails)->map(fn (string $email) => CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => $email,
            'email' => $email,
            'type' => 'customer',
            'region' => self::REGION,
            'status' => 'new',
            'currency' => 'EGP',
        ]));
    }

    private function sync(User $admin, array $overrides = [], array $criteria = ['regions' => [self::REGION]]): TikTokAudienceSync
    {
        $connection = TikTokConnection::create([
            'company_id' => $admin->company_id,
            'status' => 'connected',
            'connection_method' => 'oauth',
            'app_id' => 'tt-app-a',
            'app_secret' => 'tt-secret-a',
            'access_token' => 'tt-token-a',
            'default_advertiser_id' => 'adv-a',
        ]);

        $segment = CRMSegment::create([
            'company_id' => $admin->company_id,
            'name' => 'Buyers '.uniqid(),
            'criteria' => $criteria,
        ]);

        return TikTokAudienceSync::create(array_merge([
            'company_id' => $admin->company_id,
            'tiktok_connection_id' => $connection->id,
            'crm_segment_id' => $segment->id,
            'advertiser_id' => 'adv-a',
            'audience_name' => 'CRM buyers',
            'calculate_type' => 'EMAIL_SHA256',
            'status' => 'pending',
        ], $overrides));
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
