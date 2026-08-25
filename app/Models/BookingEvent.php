<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail of a booking's status transitions — see
 * Booking::transitionTo(). No updated_at: an event, once written, never
 * changes.
 */
class BookingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'event_type', 'actor_type', 'actor_id', 'payload', 'created_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
