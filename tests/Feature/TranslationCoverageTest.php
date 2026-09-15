<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every supported locale must contain every application translation key.
     * This keeps newly added labels from silently falling back to English.
     */
    public function test_supported_locales_have_all_application_translation_keys(): void
    {
        $english = json_decode(file_get_contents(base_path('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (User::SUPPORTED_LOCALES as $locale) {
            $catalog = json_decode(file_get_contents(base_path("resources/lang/{$locale}.json")), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame([], array_diff_key($english, $catalog), "Missing application translations for {$locale}.");
        }
    }

    public function test_filament_shield_has_translations_for_supported_non_english_locales(): void
    {
        $english = require base_path('vendor/bezhansalleh/filament-shield/resources/lang/en/filament-shield.php');

        foreach (['it', 'bn'] as $locale) {
            $catalog = require base_path("resources/lang/vendor/filament-shield/{$locale}/filament-shield.php");

            $this->assertSame([], array_diff_key($english, $catalog), "Missing Shield translations for {$locale}.");
        }
    }
}
