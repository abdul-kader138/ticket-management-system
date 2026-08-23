<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Listeners\LogPermissionActivity;
use App\Models\Setting;
use App\Policies\ActivityPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyMailSettings();
        $this->applyGoogleOAuthSettings();

        // Every password field in the app — registration, forgot-password
        // reset, profile password change, and admin-created users — calls
        // Password::default() (Filament's own auth pages do this out of the
        // box; UserResource opts in explicitly). Without this, that default
        // is just Password::min(8) with no actual strength requirement.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers());

        Event::subscribe(LogAuthenticationActivity::class);
        Event::subscribe(LogPermissionActivity::class);

        // Activity (Spatie\Activitylog\Models\Activity) lives outside
        // App\Models, so Laravel's policy auto-discovery never finds the
        // shield:generate'd App\Policies\ActivityPolicy on its own —
        // ActivityLogResource's viewAny/view authorization depends on this.
        Gate::policy(Activity::class, ActivityPolicy::class);
    }

    /**
     * Mail config is read by Laravel's mailer once per process and cached
     * internally, so it must be pushed into config() at boot rather than
     * read on demand like other settings.
     */
    private function applyMailSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config([
            'mail.from.name'    => Setting::get('mail_from_name', config('mail.from.name')),
            'mail.from.address' => Setting::get('mail_from_address', config('mail.from.address')),
        ]);

        if ($host = Setting::get('mail_host')) {
            config([
                'mail.default'               => 'smtp',
                'mail.mailers.smtp.host'     => $host,
                'mail.mailers.smtp.port'     => Setting::get('mail_port', 587),
                'mail.mailers.smtp.username' => Setting::get('mail_username'),
                'mail.mailers.smtp.password' => Setting::get('mail_password'),
                // The stored value is the admin-facing choice ("tls"/"ssl" —
                // see SystemSettings' mail_encryption select), NOT a valid
                // Symfony Mailer scheme. Symfony only accepts "smtp"
                // (STARTTLS, negotiated automatically — what "TLS" on port
                // 587 means in practice) or "smtps" (implicit TLS, port
                // 465 — what "SSL" means). Passing "tls"/"ssl" straight
                // through throws UnsupportedSchemeException and silently
                // fails every queued mail job.
                'mail.mailers.smtp.scheme'   => Setting::get('mail_encryption') === 'ssl' ? 'smtps' : 'smtp',
            ]);
        }
    }

    /**
     * Same rationale as applyMailSettings(): Socialite resolves
     * config('services.google.*') once when its driver is built, so
     * admin-panel-configured credentials (System Settings → Security)
     * must land in config() at boot to take effect. Falls through to
     * whatever .env already provided when a setting is blank — either
     * source works, DB just wins if both are set.
     */
    private function applyGoogleOAuthSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        config([
            'services.google.client_id' => Setting::get('google_client_id') ?: config('services.google.client_id'),
            'services.google.client_secret' => Setting::get('google_client_secret') ?: config('services.google.client_secret'),
        ]);
    }
}
