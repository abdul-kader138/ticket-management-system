<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
    }

    public function test_a_user_can_export_their_own_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/account/export')
            ->assertOk()
            ->assertJsonStructure(['profile', 'traveler_profiles', 'bookings', 'payments', 'subscriptions']);
    }

    public function test_deleting_the_account_requires_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/account', ['password' => 'wrong-password'])
            ->assertUnprocessable();

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_deleting_the_account_anonymizes_and_logs_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123')]);

        $this->actingAs($user, 'web')
            ->deleteJson('/api/v1/account', ['password' => 'Password123'])
            ->assertOk();

        $this->assertSame('Deleted', $user->fresh()->first_name);
        $this->assertGuest('web');
    }
}
