<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_link_google_account_and_login_with_it(): void
    {
        $this->seed(DatabaseSeeder::class);
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.google.redirect_uri' => 'http://localhost:5173/auth/google/callback',
        ]);

        $user = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($user);

        $linkUrl = $this->getJson('/api/auth/google/link-url')
            ->assertOk()
            ->json('data.url');
        parse_str(parse_url($linkUrl, PHP_URL_QUERY), $linkQuery);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-user-123',
                'email' => 'company.admin@gmail.com',
                'email_verified' => true,
                'name' => 'Google Admin',
                'picture' => 'https://example.test/avatar.png',
            ], 200),
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'link-code',
            'state' => $linkQuery['state'],
        ])->assertOk()
            ->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.user.social_accounts.0.provider', 'google');

        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-123',
        ]);

        $loginUrl = $this->getJson('/api/auth/google/url')
            ->assertOk()
            ->json('data.url');
        parse_str(parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'login-code',
            'state' => $loginQuery['state'],
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_unlinked_google_email_for_existing_user_is_linked_and_logged_in(): void
    {
        $this->seed(DatabaseSeeder::class);
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.google.redirect_uri' => 'http://localhost:5173/auth/google/callback',
        ]);

        $user = User::where('email', 'company.admin@example.com')->firstOrFail();
        $loginUrl = $this->getJson('/api/auth/google/url')->assertOk()->json('data.url');
        parse_str(parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-existing-user',
                'email' => $user->email,
                'email_verified' => true,
                'name' => $user->name,
            ], 200),
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'login-code',
            'state' => $loginQuery['state'],
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-existing-user',
        ]);
    }

    public function test_unlinked_google_email_without_user_returns_registration_required(): void
    {
        $this->seed(DatabaseSeeder::class);
        config([
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.google.redirect_uri' => 'http://localhost:5173/auth/google/callback',
        ]);

        $loginUrl = $this->getJson('/api/auth/google/url')->assertOk()->json('data.url');
        parse_str(parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-token'], 200),
            'https://www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-new-user',
                'email' => 'new.google.user@example.com',
                'email_verified' => true,
                'name' => 'New Google User',
            ], 200),
        ]);

        $this->postJson('/api/auth/google/callback', [
            'code' => 'login-code',
            'state' => $loginQuery['state'],
        ])->assertOk()
            ->assertJsonPath('data.requires_registration', true)
            ->assertJsonPath('data.profile.email', 'new.google.user@example.com');
    }
}
