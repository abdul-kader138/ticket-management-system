<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class TwoFactorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
    }

    public function test_setup_returns_a_secret_and_qr_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/two-factor/setup')
            ->assertOk()
            ->assertJsonStructure(['secret', 'qr_code_svg']);
    }

    public function test_confirm_with_a_valid_code_enables_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/two-factor/confirm', ['secret' => $secret, 'code' => $code])
            ->assertOk();

        $this->assertNotEmpty($response->json('recovery_codes'));
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirm_with_an_invalid_code_does_not_enable_two_factor(): void
    {
        $user = User::factory()->create();
        $secret = app(Google2FA::class)->generateSecretKey();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/two-factor/confirm', ['secret' => $secret, 'code' => '000000'])
            ->assertUnprocessable();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_setup_is_rejected_when_two_factor_is_disabled_platform_wide(): void
    {
        Setting::set('two_factor_enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/two-factor/setup')
            ->assertStatus(422);
    }

    public function test_disabling_two_factor_requires_the_correct_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => ['x'],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/account/two-factor', ['password' => 'wrong'])
            ->assertUnprocessable();

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_disabling_two_factor_with_the_correct_password_clears_it(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123'),
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => ['x'],
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/account/two-factor', ['password' => 'Password123'])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }
}
