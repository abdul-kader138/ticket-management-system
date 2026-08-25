<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
    }

    private function userWithTwoFactor(string $secret): User
    {
        return User::factory()->create([
            'password' => Hash::make('Password123'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['recovery-code-1'],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_logging_in_without_two_factor_enabled_logs_in_immediately(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_logging_in_with_two_factor_enabled_returns_a_challenge_instead_of_logging_in(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true);

        $this->assertNotEmpty($response->json('challenge_token'));
        $this->assertGuest('web');
    }

    public function test_a_valid_code_completes_the_challenge_and_logs_in(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->json();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/login/challenge', [
            'challenge_token' => $challenge['challenge_token'],
            'code' => $code,
        ])->assertOk()->assertJsonPath('id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_an_invalid_code_does_not_log_in(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->json();

        $this->postJson('/api/v1/auth/login/challenge', [
            'challenge_token' => $challenge['challenge_token'],
            'code' => '000000',
        ])->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_a_recovery_code_completes_the_challenge_and_is_single_use(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->json();

        $this->postJson('/api/v1/auth/login/challenge', [
            'challenge_token' => $challenge['challenge_token'],
            'code' => 'recovery-code-1',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotContains('recovery-code-1', $user->fresh()->two_factor_recovery_codes);
    }

    public function test_a_challenge_token_cannot_be_reused_after_success(): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->json();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/login/challenge', ['challenge_token' => $challenge['challenge_token'], 'code' => $code])
            ->assertOk();

        $this->postJson('/api/v1/auth/logout');

        $this->postJson('/api/v1/auth/login/challenge', ['challenge_token' => $challenge['challenge_token'], 'code' => $code])
            ->assertUnprocessable();
    }

    public function test_an_expired_or_unknown_challenge_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login/challenge', [
            'challenge_token' => 'not-a-real-token',
            'code' => '123456',
        ])->assertUnprocessable();
    }
}
