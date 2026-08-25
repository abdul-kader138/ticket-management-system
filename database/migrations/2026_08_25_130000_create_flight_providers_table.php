<?php

use App\Services\Flights\DuffelClient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_providers', function (Blueprint $table) {
            $table->id();
            // Matches the class's driver key (see FlightProviderManager) —
            // not a display label, so it's what code switches on.
            $table->string('code')->unique();
            $table->string('name');
            $table->string('driver_class');
            $table->string('base_url')->nullable();
            $table->string('environment')->default('sandbox');
            // Token/secret only — never card or passenger data — so a
            // straight encrypted cast (see App\Models\FlightProvider) is
            // enough without a dedicated secrets store.
            $table->text('credentials')->nullable();
            $table->boolean('is_enabled')->default(false);
            // Search fan-out order when more than one provider is enabled;
            // lower runs first (see FlightProviderManager::enabledProviders()).
            $table->unsignedSmallInteger('priority')->default(0);
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->timestamps();
        });

        $this->migrateExistingDuffelSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_providers');
    }

    /**
     * Carries forward whatever an admin already configured on the old
     * System Settings → "Flight API" tab (single hardcoded Duffel
     * integration) into the new multi-provider table, then removes those
     * now-unused settings rows — see docs/ROADMAP.md, Phase 2.
     */
    private function migrateExistingDuffelSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $keys = [
            'flight_api_enabled', 'flight_api_provider', 'flight_api_base_url',
            'flight_api_token', 'flight_api_environment', 'flight_api_timeout',
        ];

        $settings = DB::table('settings')->whereIn('key', $keys)->pluck('value', 'key');

        if ($settings->isEmpty()) {
            return;
        }

        $token = $settings->get('flight_api_token');

        if (filled($token)) {
            DB::table('flight_providers')->insert([
                'code' => 'duffel',
                'name' => $settings->get('flight_api_provider') ?: 'Duffel',
                'driver_class' => DuffelClient::class,
                'base_url' => $settings->get('flight_api_base_url') ?: 'https://api.duffel.com',
                'environment' => $settings->get('flight_api_environment') ?: 'sandbox',
                // Must match FlightProvider's `encrypted:array` cast exactly
                // (Crypt::encryptString + json, not the encrypt() helper,
                // which also PHP-serializes — the cast's decryptString()
                // side doesn't unserialize, so that mismatch decrypts to
                // garbage silently instead of throwing).
                'credentials' => Crypt::encryptString(json_encode(['token' => $token])),
                'is_enabled' => filter_var($settings->get('flight_api_enabled'), FILTER_VALIDATE_BOOLEAN),
                'priority' => 0,
                'timeout' => (int) ($settings->get('flight_api_timeout') ?: 30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->whereIn('key', $keys)->delete();
        foreach ($keys as $key) {
            Cache::forget("setting:{$key}");
        }
    }
};
