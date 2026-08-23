<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class LoginTwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    private function makeTwoFactorUser(string $secret = 'JBSWY3DPEHPK3PXP'): User
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => bcrypt('password123'),
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAA-BBBB', 'CCCC-DDDD'],
            'two_factor_confirmed_at' => now(),
        ]);
        $user->assignRole('panel_user');

        return $user;
    }

    private function validCodeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_user_without_two_factor_logs_in_directly(): void
    {
        $user = User::factory()->create(['email' => 'plain@example.com', 'password' => bcrypt('password123')]);
        $user->assignRole('panel_user');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'plain@example.com', 'password' => 'password123'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_with_two_factor_is_challenged_instead_of_logged_in_immediately(): void
    {
        $this->makeTwoFactorUser();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate')
            ->assertSet('twoFactorChallenge', true);

        $this->assertGuest();
    }

    public function test_correct_totp_code_completes_login(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->makeTwoFactorUser($secret);

        $test = Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate')
            ->assertSet('twoFactorChallenge', true);

        $test->fillForm(['code' => $this->validCodeFor($secret)])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_incorrect_totp_code_fails_and_does_not_log_in(): void
    {
        $this->makeTwoFactorUser();

        $test = Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate');

        $test->fillForm(['code' => '000000'])
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_recovery_code_completes_login_and_is_single_use(): void
    {
        $user = $this->makeTwoFactorUser();

        $test = Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate');

        $test->fillForm(['code' => 'AAAA-BBBB'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame(['CCCC-DDDD'], $user->two_factor_recovery_codes);

        auth()->logout();

        // The same recovery code cannot be reused on a second login.
        $test2 = Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate');

        $test2->fillForm(['code' => 'AAAA-BBBB'])
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_back_to_login_resets_the_challenge(): void
    {
        $this->makeTwoFactorUser();

        $test = Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate')
            ->assertSet('twoFactorChallenge', true);

        $test->call('resetTwoFactorChallenge')
            ->assertSet('twoFactorChallenge', false);

        $this->assertNull(session('two_factor_authentication_user_id'));
    }

    public function test_wrong_password_still_fails_even_for_a_two_factor_user(): void
    {
        $this->makeTwoFactorUser();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_globally_disabling_two_factor_skips_the_challenge_even_for_an_enabled_user(): void
    {
        Setting::set('two_factor_enabled', false);
        $user = $this->makeTwoFactorUser();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'staff@example.com', 'password' => 'password123'])
            ->call('authenticate')
            ->assertSet('twoFactorChallenge', false);

        $this->assertAuthenticatedAs($user);
    }
}
