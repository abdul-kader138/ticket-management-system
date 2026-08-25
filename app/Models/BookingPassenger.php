<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    protected $fillable = [
        'booking_id', 'traveler_profile_id', 'type', 'first_name', 'last_name',
        'date_of_birth', 'ticket_number',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function travelerProfile(): BelongsTo
    {
        return $this->belongsTo(TravelerProfile::class);
    }
}
