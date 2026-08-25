<?php

namespace App\Models;

use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['first_name', 'last_name', 'email', 'phone', 'marketing_opt_in', 'password', 'avatar', 'google_id', 'email_verified_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    use \Illuminate\Auth\MustVerifyEmail;

    // Not in #[Fillable] above — deliberately never mass-assignable (see
    // App\Observers\BookingObserver, the only writer). Set as an in-memory
    // default rather than relying on the column's DB-side default, since
    // Eloquent doesn't re-fetch after create() — without this, a
    // freshly-created User's total_spend_cents reads as null in the same
    // request until the model is reloaded from the database.
    protected $attributes = [
        'total_spend_cents' => 0,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'marketing_opt_in' => 'boolean',
            'total_spend_cents' => 'integer',
            // Encrypted at rest — plain Eloquent casts, no extra package needed.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // Computed, not stored — `name` is split into first_name/last_name
    // columns, but plenty of framework code (Notifiable's default "Hello"
    // greeting, activity log display, etc.) still expects a `name` attribute
    // to just work.
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['super_admin', 'panel_user'])
            || $this->getAllPermissions()->isNotEmpty();
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return filled($this->two_factor_secret) && filled($this->two_factor_confirmed_at);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Customers (registered users with no Filament role/permission — see
     * canAccessPanel() above) never see Filament's own verify/reset pages,
     * so their email links need to point at the customer app instead.
     *
     * Safe to override here: Filament's own auth pages build and send their
     * own Filament\Notifications\Auth\* notifications directly (see
     * RequestPasswordReset::request() and EmailVerificationPrompt::
     * sendEmailVerificationNotification()) rather than calling these
     * Authenticatable defaults, so staff password-reset/verification is
     * unaffected by this override.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function travelerProfiles(): HasMany
    {
        return $this->hasMany(TravelerProfile::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    /**
     * Shareable, not secret — a referral code only identifies who to credit,
     * it grants nothing by itself (see App\Observers\BookingObserver, which
     * only rewards the referrer once the referred user's first booking
     * confirms). Base-36 of the id rather than a stored random token, so no
     * extra column/table is needed just to hand every user a code.
     */
    public function referralCode(): string
    {
        return 'REF'.strtoupper(base_convert((string) $this->id, 10, 36));
    }

    public static function findByReferralCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        if (! str_starts_with($code, 'REF') || $code === 'REF') {
            return null;
        }

        $id = (int) base_convert(substr($code, 3), 36, 10);

        return $id > 0 ? static::find($id) : null;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }
}
