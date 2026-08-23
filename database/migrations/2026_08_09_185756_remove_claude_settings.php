<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only cleanup: Claude/Anthropic drafting was replaced by deterministic
 * clause-based generation. There's no dedicated settings seeder to otherwise
 * clean these up, so any dormant rows would linger in a deployed DB forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->whereIn('key', ['claude_api_key', 'claude_model', 'claude_max_tokens', 'claude_temperature'])
            ->delete();
    }

    public function down(): void
    {
        // Deliberately not restorable — these were dormant, unused settings.
    }
};
