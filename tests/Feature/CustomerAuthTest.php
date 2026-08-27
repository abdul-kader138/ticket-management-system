<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's statefulApi() only starts a session for requests that
        // look like they came from the SPA (Referer/Origin matching
        // config('sanctum.stateful')) — a real browser XHR always sends
        // one, so tests need to fake it too or every session()-touching
        // controller 500s with "Session store not set on request."
        $this->withHeader('Referer', config('app.frontend_url'));
    }

    public function test_a_visitor_can_register_and_gets_no_panel_role(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonPath('email_verified', false);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($user->getAllPermissions()->isEmpty());
        $this->assertFalse($user->canAccessPanel(Filament::getDefaultPanel()));

        // Registration does not sign the user in — an unverified account
        // holds no session.
        $this->assertGuest('web');
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_unverified_account_cannot_log_in_and_is_sent_a_fresh_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['password' => Hash::make('Password123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $this->assertGuest('web');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_unverified_session_is_blocked_from_the_customer_api(): void
    {
        $user = User::factory()->unverified()->create();

        // Even if a session is somehow established (e.g. the OAuth path),
        // the 'verified' middleware fences off every resource endpoint.
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/account')
            ->assertForbidden();
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk()->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_login_fails_with_the_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_an_unauthenticated_user_cannot_reach_the_account_endpoint(): void
    {
        $this->getJson('/api/v1/account')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_view_and_update_their_account(): void
    {
        $user = User::factory()->create(['phone' => null]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/account')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/account', ['phone' => '+15551234567'])
            ->assertOk()
            ->assertJsonPath('phone', '+15551234567');

        $this->assertSame('+15551234567', $user->fresh()->phone);
    }

    public function test_changing_email_resets_verification_and_resends_it(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/account', ['email' => 'new-address@example.com'])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('new-address@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);

        Notification::assertSentTo($fresh, VerifyEmail::class);
    }

    public function test_a_signed_verification_link_marks_the_email_verified_without_a_session(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        // No actingAs() — the signed link is the credential.
        $this->get($url)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_verification_link_whose_hash_does_not_match_the_account_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('someone-elses-address@example.com'),
        ]);

        $this->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_sends_a_reset_link_for_a_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_responds_the_same_way_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);

        $token = Password::broker('users')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }
}
