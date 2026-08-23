<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class EditProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    private function makeStaff(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('panel_user');

        return $user;
    }

    public function test_authenticated_user_can_load_the_profile_page(): void
    {
        $user = $this->makeStaff();

        $this->actingAs($user)->get('/admin/profile')->assertOk();
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = $this->makeStaff(['first_name' => 'Old', 'last_name' => 'Name', 'email' => 'old@example.com']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['first_name' => 'New', 'last_name' => 'Name', 'email' => 'new@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_user_can_change_password(): void
    {
        $user = $this->makeStaff(['email' => 'pwtest@example.com']);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'password' => 'New-Password-123',
                'passwordConfirmation' => 'New-Password-123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('New-Password-123', $user->password));
    }

    public function test_two_factor_actions_do_not_exist_when_globally_disabled(): void
    {
        Setting::set('two_factor_enabled', false);
        $user = $this->makeStaff();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertActionDoesNotExist('enableTwoFactor')
            ->assertActionDoesNotExist('disableTwoFactor');
    }

    public function test_enable_two_factor_action_visible_when_not_yet_enabled(): void
    {
        $user = $this->makeStaff();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertActionVisible('enableTwoFactor')
            ->assertActionHidden('disableTwoFactor')
            ->assertActionHidden('regenerateRecoveryCodes')
            ->assertActionHidden('showRecoveryCodes');
    }

    public function test_management_actions_visible_once_two_factor_enabled(): void
    {
        $user = $this->makeStaff([
            'two_factor_secret' => 'SECRET',
            'two_factor_recovery_codes' => ['AAAA-BBBB'],
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertActionHidden('enableTwoFactor')
            ->assertActionVisible('disableTwoFactor')
            ->assertActionVisible('regenerateRecoveryCodes')
            ->assertActionVisible('showRecoveryCodes');
    }

    public function test_disable_two_factor_action_clears_the_users_secret(): void
    {
        $user = $this->makeStaff([
            'two_factor_secret' => 'SECRET',
            'two_factor_recovery_codes' => ['AAAA-BBBB'],
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->callAction('disableTwoFactor');

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_regenerate_recovery_codes_replaces_the_old_set(): void
    {
        $user = $this->makeStaff([
            'two_factor_secret' => 'SECRET',
            'two_factor_recovery_codes' => ['AAAA-BBBB'],
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->callAction('regenerateRecoveryCodes');

        $user->refresh();
        $this->assertNotSame(['AAAA-BBBB'], $user->two_factor_recovery_codes);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_enable_two_factor_flow_with_a_valid_code_confirms_setup(): void
    {
        $user = $this->makeStaff();
        $this->actingAs($user);

        $test = Livewire::test(EditProfile::class)
            ->mountAction('enableTwoFactor');

        // Read the fillForm()-generated secret straight off the raw Livewire
        // property — Form::getState() would work too, but it also VALIDATES
        // the whole form (including the still-empty, required "code" field),
        // throwing before we ever get to fill it in.
        $secret = $test->instance()->mountedActionsData[array_key_last($test->instance()->mountedActionsData)]['secret'];
        $validCode = app(Google2FA::class)->getCurrentOtp($secret);

        $test->setActionData(['code' => $validCode])
            ->callMountedAction();

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_enable_two_factor_flow_with_an_invalid_code_fails(): void
    {
        $user = $this->makeStaff();
        $this->actingAs($user);

        $test = Livewire::test(EditProfile::class)
            ->mountAction('enableTwoFactor');

        $test->setActionData(['code' => '000000'])
            ->callMountedAction()
            ->assertHasErrors();

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }
}
