<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RuntimeException;

/**
 * See docs/ROADMAP.md, Phase 4. Status only ever changes through
 * transitionTo() — nothing should write to the `status` column directly,
 * so every change is guarded by ALLOWED_TRANSITIONS and logged to
 * `booking_events`.
 */
class Booking extends Model
{
    public const STATUS_HELD = 'held';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CHANGED = 'changed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_HELD => [self::STATUS_PENDING_PAYMENT, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_PENDING_PAYMENT => [self::STATUS_CONFIRMED, self::STATUS_HELD, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_CHANGED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_CHANGED => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
        self::STATUS_EXPIRED => [],
    ];

    protected $fillable = [
        'user_id', 'flight_provider_id', 'provider_offer_id', 'provider_order_id', 'pnr',
        'status', 'currency', 'total_price_cents', 'cabin_class', 'expires_at', 'terms_version',
    ];

    protected function casts(): array
    {
        return [
            'total_price_cents' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function flightProvider(): BelongsTo
    {
        return $this->belongsTo(FlightProvider::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(BookingSegment::class)->orderBy('sequence');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->latest('created_at');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    public function hasExpired(): bool
    {
        return $this->isHeld() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException
     */
    public function transitionTo(string $status, string $actorType = 'system', ?int $actorId = null, array $payload = []): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException("Booking #{$this->id} cannot move from '{$this->status}' to '{$status}'.");
        }

        $this->status = $status;

        if ($status === self::STATUS_CONFIRMED) {
            $this->confirmed_at = now();
        }

        if (in_array($status, [self::STATUS_CANCELLED, self::STATUS_REFUNDED], true)) {
            $this->cancelled_at = now();
        }

        $this->save();

        $this->events()->create([
            'event_type' => $status,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
