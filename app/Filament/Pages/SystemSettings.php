<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Providers\Filament\AdminPanelProvider;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemSettings extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 99;

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'System Settings';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Administration';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $superAdminName = (string) config('filament-shield.super_admin.name', 'super_admin');

        if ((bool) config('filament-shield.super_admin.enabled', true) && $user->hasRole($superAdminName)) {
            return true;
        }

        return $user->can('page_SystemSettings');
    }

    public function getTitle(): string
    {
        return 'System Settings';
    }

    public function getView(): string
    {
        return 'filament.pages.system-settings';
    }

    public function mount(): void
    {
        $this->form->fill([
            // General
            'app_name' => Setting::get('app_name', 'Ticket Management System'),
            'app_tagline' => Setting::get('app_tagline', 'Track and resolve support tickets, end to end.'),

            // Appearance
            'admin_theme' => Setting::get('admin_theme', 'indigo'),
            'admin_panel_theme_mode' => Setting::get('admin_panel_theme_mode', 'dark'),
            'app_logo' => Setting::get('app_logo'),
            'app_icon' => Setting::get('app_icon'),
            'favicon' => Setting::get('favicon'),

            // Security
            'two_factor_enabled' => Setting::get('two_factor_enabled', true),
            'google_client_id' => Setting::get('google_client_id', config('services.google.client_id', '')),
            'google_client_secret' => Setting::get('google_client_secret', config('services.google.client_secret', '')),

            // Email
            'mail_from_name' => Setting::get('mail_from_name', config('mail.from.name', '')),
            'mail_from_address' => Setting::get('mail_from_address', config('mail.from.address', '')),
            'mail_host' => Setting::get('mail_host', ''),
            'mail_port' => Setting::get('mail_port', 587),
            'mail_username' => Setting::get('mail_username', ''),
            'mail_password' => Setting::get('mail_password', ''),
            'mail_encryption' => Setting::get('mail_encryption', 'tls'),
            'staff_notification_email' => Setting::get('staff_notification_email', ''),

            // Search Quotas
            'default_daily_search_limit' => Setting::get('default_daily_search_limit', 10),
            'default_monthly_search_limit' => Setting::get('default_monthly_search_limit', 300),
            'referral_reward_bonus_searches' => Setting::get('referral_reward_bonus_searches', 20),

            // Payments
            'stripe_secret_key' => Setting::get('stripe_secret_key', ''),
            'stripe_publishable_key' => Setting::get('stripe_publishable_key', ''),
            'stripe_webhook_secret' => Setting::get('stripe_webhook_secret', ''),
            'paypal_mode' => Setting::get('paypal_mode', 'sandbox'),
            'paypal_client_id' => Setting::get('paypal_client_id', ''),
            'paypal_client_secret' => Setting::get('paypal_client_secret', ''),
            'paypal_webhook_id' => Setting::get('paypal_webhook_id', ''),

            // Compliance
            'current_terms_version' => Setting::get('current_terms_version', 'v1'),
            'refund_policy_text' => Setting::get('refund_policy_text', ''),
            'data_retention_days' => Setting::get('data_retention_days', 180),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Tabs::make('settings_tabs')->tabs([

                    // ── General ──────────────────────────────────────────────
                    Tab::make('General')
                        ->icon('heroicon-o-home')
                        ->schema([
                            Section::make('Application')
                                ->description('Shown in the admin panel header and on the login page.')
                                ->schema([
                                    TextInput::make('app_name')
                                        ->label('Application Name')
                                        ->required()
                                        ->maxLength(100),

                                    TextInput::make('app_tagline')
                                        ->label('Tagline')
                                        ->maxLength(200),
                                ])->columns(2),
                        ]),

                    // ── Appearance ───────────────────────────────────────────
                    Tab::make('Appearance')
                        ->icon('heroicon-o-swatch')
                        ->schema([
                            Section::make('Color Theme')
                                ->description('Choose a color scheme for the admin panel. Save and refresh to apply.')
                                ->schema([
                                    Radio::make('admin_theme')
                                        ->label('Admin Panel Theme')
                                        ->helperText('The selected theme applies to all admin panel pages.')
                                        ->options(
                                            collect(AdminPanelProvider::$themes)
                                                ->mapWithKeys(fn ($t, $key) => [$key => $t['label']])
                                                ->toArray()
                                        )
                                        ->columns(4)
                                        ->required(),
                                ]),

                            Section::make('Panel Mode')
                                ->description('Control the light/dark mode of the admin panel shell.')
                                ->schema([
                                    Radio::make('admin_panel_theme_mode')
                                        ->label('Admin Panel Mode')
                                        ->helperText('Changes take effect after saving and refreshing.')
                                        ->options([
                                            'light' => 'Light',
                                            'dark' => 'Dark',
                                            'system' => 'System',
                                            'high_contrast' => 'High Contrast',
                                            'sepia' => 'Sepia',
                                            'midnight' => 'Midnight',
                                        ])
                                        ->descriptions([
                                            'light' => 'Always show the admin panel in light mode.',
                                            'dark' => 'Always show the admin panel in dark mode.',
                                            'system' => "Follow the user's OS dark/light preference.",
                                            'high_contrast' => 'Stronger contrast dark mode for better accessibility.',
                                            'sepia' => 'Warm light theme with a soft paper-like tone.',
                                            'midnight' => 'Deeper blue-dark shell for a premium look.',
                                        ])
                                        ->inline()
                                        ->required(),
                                ]),

                            Section::make('Branding')
                                ->description('Upload logos and images. Run `php artisan storage:link` if images do not appear.')
                                ->schema([
                                    Grid::make(3)->schema([
                                        FileUpload::make('app_logo')
                                            ->label('Application Logo')
                                            ->image()
                                            ->disk('public')
                                            ->directory('branding')
                                            ->visibility('public')
                                            ->helperText('Shown in the admin panel sidebar header. Leave blank to use the application name as text.'),

                                        FileUpload::make('app_icon')
                                            ->label('App Icon / Favicon')
                                            ->image()
                                            ->disk('public')
                                            ->directory('branding')
                                            ->visibility('public')
                                            ->acceptedFileTypes(['image/x-icon', 'image/png', 'image/svg+xml'])
                                            ->helperText('Browser tab icon.'),

                                        FileUpload::make('favicon')
                                            ->label('Favicon (alternative)')
                                            ->image()
                                            ->disk('public')
                                            ->directory('branding')
                                            ->visibility('public')
                                            ->acceptedFileTypes(['image/x-icon', 'image/png', 'image/svg+xml'])
                                            ->helperText('Overrides the app icon for browser tabs.'),
                                    ]),
                                ]),
                        ]),

                    // ── Security ─────────────────────────────────────────────
                    Tab::make('Security')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            Section::make('Two-Factor Authentication')
                                ->description('Applies firm-wide. Individual users still opt in from their own profile page — this is the master switch.')
                                ->schema([
                                    Toggle::make('two_factor_enabled')
                                        ->label('Allow two-factor authentication')
                                        ->default(true)
                                        ->helperText('Turning this off hides 2FA setup from every profile page and skips the login challenge for everyone, even users who previously enabled it.'),
                                ]),

                            Section::make('Google Sign-In')
                                ->description('Lets users sign in with a Google account instead of a password. Leave blank to hide the "Continue with Google" button. Create credentials at console.cloud.google.com/apis/credentials.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('google_client_id')
                                            ->label('Client ID')
                                            ->maxLength(255),

                                        TextInput::make('google_client_secret')
                                            ->label('Client Secret')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->maxLength(255),
                                    ]),

                                    Placeholder::make('google_redirect_uri')
                                        ->label('Authorized redirect URI')
                                        ->content(fn () => route('auth.google.callback'))
                                        ->helperText('Add this exact URL to the OAuth client\'s "Authorized redirect URIs" in Google Cloud Console.'),
                                ]),
                        ]),

                    // ── Email ────────────────────────────────────────────────
                    Tab::make('Email')
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            Section::make('Sender')->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('mail_from_name')
                                        ->label('From Name')
                                        ->required()
                                        ->maxLength(100),

                                    TextInput::make('mail_from_address')
                                        ->label('From Address')
                                        ->email()
                                        ->required()
                                        ->maxLength(255),
                                ]),

                                TextInput::make('staff_notification_email')
                                    ->label('Staff Notification Email')
                                    ->email()
                                    ->maxLength(255)
                                    ->helperText('Where system alerts are sent. Leave blank to disable.'),
                            ]),

                            Section::make('SMTP Server')
                                ->description('Configure an SMTP provider to actually deliver emails. Leave the host blank to keep using the default log/array driver.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('mail_host')
                                            ->label('SMTP Host')
                                            ->placeholder('smtp-relay.brevo.com')
                                            ->maxLength(255),

                                        TextInput::make('mail_port')
                                            ->label('SMTP Port')
                                            ->numeric()
                                            ->placeholder('587'),
                                    ]),

                                    Grid::make(2)->schema([
                                        TextInput::make('mail_username')
                                            ->label('SMTP Username')
                                            ->maxLength(255),

                                        TextInput::make('mail_password')
                                            ->label('SMTP Password')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->maxLength(255),
                                    ]),

                                    Select::make('mail_encryption')
                                        ->label('Encryption')
                                        ->options([
                                            'tls' => 'TLS',
                                            'ssl' => 'SSL',
                                        ])
                                        ->native(false),
                                ]),
                        ]),

                    // ── Search Quotas ────────────────────────────────────────
                    Tab::make('Search Quotas')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Section::make('Default Flight Search Limits')
                                ->description('Applies to every account with no paid subscription plan (see Subscription Plans, once configured). Flight search hits a paid provider API per request, so this protects against runaway cost.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('default_daily_search_limit')
                                            ->label('Searches per day')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(10)
                                            ->required(),

                                        TextInput::make('default_monthly_search_limit')
                                            ->label('Searches per month')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(300)
                                            ->required(),
                                    ]),
                                ]),

                            Section::make('Referral Program')
                                ->description('Granted automatically to the referrer once the person they referred confirms their first booking — see Promotions for admin-issued codes.')
                                ->schema([
                                    TextInput::make('referral_reward_bonus_searches')
                                        ->label('Bonus searches per successful referral')
                                        ->numeric()
                                        ->minValue(0)
                                        ->default(20)
                                        ->required(),
                                ]),
                        ]),

                    // ── Payments ─────────────────────────────────────────────
                    Tab::make('Payments')
                        ->icon('heroicon-o-credit-card')
                        ->schema([
                            Section::make('Stripe')
                                ->description('Card payments via PaymentIntents + Elements — card details never touch this server. Get keys from the Stripe Dashboard → Developers → API keys, and create a webhook endpoint pointing at the URL below.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('stripe_publishable_key')
                                            ->label('Publishable Key')
                                            ->maxLength(255),

                                        TextInput::make('stripe_secret_key')
                                            ->label('Secret Key')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->maxLength(255),
                                    ]),

                                    TextInput::make('stripe_webhook_secret')
                                        ->label('Webhook Signing Secret')
                                        ->password()
                                        ->revealable()
                                        ->autocomplete('new-password')
                                        ->maxLength(255)
                                        ->columnSpanFull(),

                                    Placeholder::make('stripe_webhook_url')
                                        ->label('Webhook URL')
                                        ->content(fn () => route('webhooks.payments', ['gateway' => 'stripe']))
                                        ->helperText('Add this as an endpoint in the Stripe Dashboard, listening for payment_intent.succeeded, payment_intent.payment_failed and charge.refunded.'),
                                ]),

                            Section::make('PayPal')
                                ->description('Orders v2 (create → customer approves on PayPal → capture). Get credentials from developer.paypal.com → Apps & Credentials, and register a webhook pointing at the URL below.')
                                ->schema([
                                    Select::make('paypal_mode')
                                        ->label('Environment')
                                        ->options(['sandbox' => 'Sandbox', 'live' => 'Live'])
                                        ->default('sandbox')
                                        ->native(false)
                                        ->required(),

                                    Grid::make(2)->schema([
                                        TextInput::make('paypal_client_id')
                                            ->label('Client ID')
                                            ->maxLength(255),

                                        TextInput::make('paypal_client_secret')
                                            ->label('Client Secret')
                                            ->password()
                                            ->revealable()
                                            ->autocomplete('new-password')
                                            ->maxLength(255),
                                    ]),

                                    TextInput::make('paypal_webhook_id')
                                        ->label('Webhook ID')
                                        ->helperText('From the webhook you register in the PayPal dashboard — required to verify incoming webhook signatures.')
                                        ->maxLength(255)
                                        ->columnSpanFull(),

                                    Placeholder::make('paypal_webhook_url')
                                        ->label('Webhook URL')
                                        ->content(fn () => route('webhooks.payments', ['gateway' => 'paypal']))
                                        ->helperText('Subscribe it to PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.DENIED and PAYMENT.CAPTURE.REFUNDED.'),
                                ]),
                        ]),

                    // ── Compliance ───────────────────────────────────────────
                    Tab::make('Compliance')
                        ->icon('heroicon-o-scale')
                        ->schema([
                            Section::make('Terms & Refund Policy')
                                ->description('The version is snapshotted onto every new booking at purchase time (Booking::terms_version) — bumping it here does not change which policy applied to an existing booking.')
                                ->schema([
                                    TextInput::make('current_terms_version')
                                        ->label('Current Version')
                                        ->default('v1')
                                        ->required()
                                        ->helperText('A short label, e.g. "v1" or "2026-08-25".'),

                                    Textarea::make('refund_policy_text')
                                        ->label('Refund Policy Summary')
                                        ->rows(4)
                                        ->helperText('Shown to customers at checkout for the version above.'),
                                ]),

                            Section::make('Data Retention')
                                ->description('How long to keep high-volume operational records with no ongoing value — see App\Console\Commands\PruneRetentionData, run weekly. Bookings, payments and refunds are never pruned by this.')
                                ->schema([
                                    TextInput::make('data_retention_days')
                                        ->label('Retention window (days)')
                                        ->numeric()
                                        ->minValue(1)
                                        ->default(180)
                                        ->required(),
                                ]),
                        ]),

                ])->persistTabInQueryString('tab'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $groups = [
            'app_name' => 'general',
            'app_tagline' => 'general',
            'admin_theme' => 'appearance',
            'admin_panel_theme_mode' => 'appearance',
            'app_logo' => 'appearance',
            'app_icon' => 'appearance',
            'favicon' => 'appearance',
            'two_factor_enabled' => 'security',
            'google_client_id' => 'security',
            'google_client_secret' => 'security',
            'mail_from_name' => 'email',
            'mail_from_address' => 'email',
            'mail_host' => 'email',
            'mail_port' => 'email',
            'mail_username' => 'email',
            'mail_password' => 'email',
            'mail_encryption' => 'email',
            'staff_notification_email' => 'email',
            'default_daily_search_limit' => 'search_quota',
            'default_monthly_search_limit' => 'search_quota',
            'referral_reward_bonus_searches' => 'search_quota',
            'stripe_secret_key' => 'payments',
            'stripe_publishable_key' => 'payments',
            'stripe_webhook_secret' => 'payments',
            'paypal_mode' => 'payments',
            'paypal_client_id' => 'payments',
            'paypal_client_secret' => 'payments',
            'paypal_webhook_id' => 'payments',
            'current_terms_version' => 'compliance',
            'refund_policy_text' => 'compliance',
            'data_retention_days' => 'compliance',
        ];

        foreach ($data as $key => $value) {
            Setting::set($key, $value ?? '', $groups[$key] ?? 'general');
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }
}
