<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSegment extends Model
{
    protected $fillable = [
        'booking_id', 'sequence', 'carrier_iata', 'carrier_name', 'flight_number',
        'origin', 'destination', 'departs_at', 'arrives_at', 'cabin_class',
    ];

    protected function casts(): array
    {
        return [
            'departs_at' => 'datetime',
            'arrives_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
