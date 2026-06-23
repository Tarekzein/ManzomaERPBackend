<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_translations_are_encrypted_cached_and_company_scoped(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);
        Http::fakeSequence()
            ->push(['translatedText' => ['مشروع توسعة المستودع']])
            ->push(['translatedText' => ['ترجمة الشركة الثانية']]);

        $payload = [
            'source_locale' => 'en',
            'target_locale' => 'ar',
            'items' => [['key' => 'project:15:description', 'text' => 'Warehouse expansion project']],
        ];

        $this->postJson('/api/translations/batch', $payload)
            ->assertOk()
            ->assertJsonPath('data.translations.project:15:description', 'مشروع توسعة المستودع');

        $this->postJson('/api/translations/batch', $payload)
            ->assertOk()
            ->assertJsonPath('data.translations.project:15:description', 'مشروع توسعة المستودع');

        Http::assertSentCount(1);
        $stored = DB::table('translation_cache')->value('translated_text');
        $this->assertNotSame('مشروع توسعة المستودع', $stored);

        $otherCompany = Company::factory()->create();
        CompanySubscription::create([
            'company_id' => $otherCompany->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'professional')->value('id'),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        Sanctum::actingAs($otherUser);

        $this->postJson('/api/translations/batch', $payload)
            ->assertOk()
            ->assertJsonPath('data.translations.project:15:description', 'ترجمة الشركة الثانية');
        Http::assertSentCount(2);
    }

    public function test_provider_failure_falls_back_to_original_text(): void
    {
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('email', 'company.admin@example.com')->firstOrFail());
        Http::fake(['*' => Http::response(['error' => 'Unavailable'], 503)]);

        $this->postJson('/api/translations/batch', [
            'source_locale' => 'en',
            'target_locale' => 'ar',
            'items' => [['key' => 'note:1', 'text' => 'Original note']],
        ])->assertOk()->assertJsonPath('data.translations.note:1', 'Original note');

        $this->assertDatabaseCount('translation_cache', 0);
    }

    public function test_translation_batch_limits_and_company_requirement_are_enforced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/translations/batch', [
            'source_locale' => 'en',
            'target_locale' => 'ar',
            'items' => [
                ['key' => 'large-1', 'text' => str_repeat('a', 4000)],
                ['key' => 'large-2', 'text' => str_repeat('b', 4000)],
                ['key' => 'large-3', 'text' => str_repeat('c', 4000)],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $platformUser = User::whereNull('company_id')->firstOrFail();
        Sanctum::actingAs($platformUser);
        $this->postJson('/api/translations/batch', [
            'source_locale' => 'en',
            'target_locale' => 'ar',
            'items' => [['key' => 'note', 'text' => 'Text']],
        ])->assertUnprocessable();
    }
}
