<?php

namespace Tests\Feature\Account;

use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Account\AccountDataService;
use App\Services\Bookings\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class AccountDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_the_users_traveler_profiles_with_passport_data(): void
    {
        $user = User::factory()->create();
        TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01', 'passport_number' => 'X1234567',
        ]);

        $export = app(AccountDataService::class)->export($user);

        $this->assertSame('Ada', $export['traveler_profiles'][0]['first_name']);
        $this->assertSame('X1234567', $export['traveler_profiles'][0]['passport_number']);
    }

    public function test_export_includes_bookings_with_segments_and_passengers(): void
    {
        FakeFlightProvider::reset();
        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '100.00', 'total_currency' => 'USD', 'slices' => []];

        $user = User::factory()->create();
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);

        $export = app(AccountDataService::class)->export($user);

        $this->assertCount(1, $export['bookings']);
        $this->assertSame('100.00', $export['bookings'][0]['total_price']);
    }

    public function test_anonymize_scrubs_identifying_fields_but_keeps_the_row(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com', 'phone' => '+15551234567']);
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01', 'passport_number' => 'X1234567',
        ]);

        app(AccountDataService::class)->anonymize($user);

        $fresh = $user->fresh();
        $this->assertSame('Deleted', $fresh->first_name);
        $this->assertNotSame('ada@example.com', $fresh->email);
        $this->assertNull($fresh->phone);

        $freshTraveler = $traveler->fresh();
        $this->assertSame('Deleted', $freshTraveler->first_name);
        $this->assertNull($freshTraveler->passport_number);
    }

    public function test_anonymize_invalidates_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);

        app(AccountDataService::class)->anonymize($user);

        $this->assertFalse(Hash::check('OldPassword123', $user->fresh()->password));
    }
}
