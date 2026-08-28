<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerDashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
    }

    public function test_a_plain_panel_user_cannot_access_the_admin_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('panel_user');

        $this->actingAs($user);

        $this->assertFalse(Dashboard::canAccess());
    }

    public function test_a_super_admin_can_access_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->actingAs($user);

        $this->assertTrue(Dashboard::canAccess());
    }

    public function test_a_customer_hitting_the_panel_root_is_redirected_to_flight_search(): void
    {
        $user = User::factory()->create();
        $user->assignRole('panel_user');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(\App\Filament\Pages\FlightSearch::getUrl());
    }
}
