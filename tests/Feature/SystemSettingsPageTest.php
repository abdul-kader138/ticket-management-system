<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_super_admin_can_view_and_save_system_settings(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/system-settings')->assertOk();

        Setting::set('app_tagline', 'Custom tagline', 'general');
        $this->assertSame('Custom tagline', Setting::get('app_tagline'));
    }

    public function test_general_appearance_and_email_fields_round_trip_through_the_real_form(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SystemSettings::class)
            ->fillForm([
                'app_name' => 'Acme Support',
                'app_tagline' => 'Tickets handled right.',
                'admin_theme' => 'emerald',
                'admin_panel_theme_mode' => 'light',
                'mail_from_name' => 'Acme Support',
                'mail_from_address' => 'no-reply@acmesupport.test',
                'staff_notification_email' => 'staff@acmesupport.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Acme Support', Setting::get('app_name'));
        $this->assertSame('Tickets handled right.', Setting::get('app_tagline'));
        $this->assertSame('emerald', Setting::get('admin_theme'));
        $this->assertSame('light', Setting::get('admin_panel_theme_mode'));
        $this->assertSame('Acme Support', Setting::get('mail_from_name'));
        $this->assertSame('no-reply@acmesupport.test', Setting::get('mail_from_address'));
        $this->assertSame('staff@acmesupport.test', Setting::get('staff_notification_email'));
    }

    public function test_booking_hold_expiry_hours_round_trips_and_drives_the_hold_window(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SystemSettings::class)
            ->fillForm(['booking_hold_expiry_hours' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(8, (int) Setting::get('booking_hold_expiry_hours'));
    }
}
