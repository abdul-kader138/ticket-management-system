<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MailVendorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function reapplyMailSettings(): void
    {
        Cache::flush();

        (new \ReflectionMethod(AppServiceProvider::class, 'applyMailSettings'))
            ->invoke(app(AppServiceProvider::class, ['app' => app()]));
    }

    public function test_the_active_vendor_profile_drives_the_smtp_config(): void
    {
        Setting::set('mail_vendors', [
            ['key' => 'brevo', 'label' => 'Brevo', 'transport' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => 'brevo-user', 'password' => 'brevo-secret', 'encryption' => 'tls'],
            ['key' => 'backup', 'label' => 'Backup', 'transport' => 'smtp', 'host' => 'smtp.mailgun.org', 'port' => 465, 'username' => 'mg-user', 'password' => 'mg-secret', 'encryption' => 'ssl'],
        ], 'email');
        Setting::set('mail_active_vendor', 'backup', 'email');

        $this->reapplyMailSettings();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.mailgun.org', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('mg-user', config('mail.mailers.smtp.username'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme')); // ssl -> smtps
    }

    public function test_switching_the_active_vendor_switches_the_transport(): void
    {
        Setting::set('mail_vendors', [
            ['key' => 'brevo', 'label' => 'Brevo', 'transport' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => 'brevo-user', 'password' => 'brevo-secret', 'encryption' => 'tls'],
            ['key' => 'devlog', 'label' => 'Dev log', 'transport' => 'log'],
        ], 'email');

        Setting::set('mail_active_vendor', 'brevo', 'email');
        $this->reapplyMailSettings();
        $this->assertSame('smtp-relay.brevo.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme')); // tls -> smtp (STARTTLS)

        Setting::set('mail_active_vendor', 'devlog', 'email');
        $this->reapplyMailSettings();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_an_active_vendor_key_that_matches_no_profile_falls_back_to_log(): void
    {
        Setting::set('mail_vendors', [
            ['key' => 'brevo', 'label' => 'Brevo', 'transport' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587],
        ], 'email');
        Setting::set('mail_active_vendor', 'renamed-away', 'email');

        $this->reapplyMailSettings();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_legacy_single_smtp_settings_still_work_when_no_vendors_are_saved(): void
    {
        Setting::set('mail_host', 'legacy.smtp.test', 'email');
        Setting::set('mail_port', 2525, 'email');
        Setting::set('mail_username', 'legacy-user', 'email');
        Setting::set('mail_encryption', 'ssl', 'email');

        $this->reapplyMailSettings();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('legacy.smtp.test', config('mail.mailers.smtp.host'));
        $this->assertSame('legacy-user', config('mail.mailers.smtp.username'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }
}
