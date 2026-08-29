<?php

namespace Tests\Feature\Bookings;

use App\Models\TravelerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TravelerProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
    }

    public function test_a_user_can_create_and_list_their_own_travelers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/traveler-profiles', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
            'passport_number' => 'X1234567',
            'passport_expiry' => now()->addYears(5)->toDateString(),
        ])->assertCreated()->assertJsonPath('first_name', 'Ada');

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/traveler-profiles')->assertOk();
        $this->assertCount(1, $response->json());

        // Passport number is write-only — never echoed back.
        $this->assertArrayNotHasKey('passport_number', $response->json()[0]);
        $this->assertTrue($response->json()[0]['has_passport']);
    }

    public function test_passport_number_and_expiry_are_required_and_expiry_must_be_in_the_future(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/traveler-profiles', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['passport_number', 'passport_expiry']);

        $this->actingAs($user, 'web')->postJson('/api/v1/traveler-profiles', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
            'passport_number' => 'X1234567',
            'passport_expiry' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['passport_expiry']);

        $this->assertDatabaseCount('traveler_profiles', 0);
    }

    public function test_a_user_cannot_update_someone_elses_traveler(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $traveler = TravelerProfile::create([
            'user_id' => $owner->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ]);

        $this->actingAs($intruder, 'web')
            ->putJson("/api/v1/traveler-profiles/{$traveler->id}", ['first_name' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame('Ada', $traveler->fresh()->first_name);
    }

    public function test_a_user_cannot_delete_someone_elses_traveler(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $traveler = TravelerProfile::create([
            'user_id' => $owner->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ]);

        $this->actingAs($intruder, 'web')
            ->deleteJson("/api/v1/traveler-profiles/{$traveler->id}")
            ->assertForbidden();

        $this->assertNotNull($traveler->fresh());
    }
}
