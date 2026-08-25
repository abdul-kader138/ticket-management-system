<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'pnr' => $this->pnr,
            'currency' => $this->currency,
            'total_price' => number_format($this->total_price_cents / 100, 2),
            'terms_version' => $this->terms_version,
            'cabin_class' => $this->cabin_class,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($segment) => [
                'sequence' => $segment->sequence,
                'carrier_iata' => $segment->carrier_iata,
                'carrier_name' => $segment->carrier_name,
                'flight_number' => $segment->flight_number,
                'origin' => $segment->origin,
                'destination' => $segment->destination,
                'departs_at' => $segment->departs_at?->toIso8601String(),
                'arrives_at' => $segment->arrives_at?->toIso8601String(),
            ])),
            'passengers' => $this->whenLoaded('passengers', fn () => $this->passengers->map(fn ($passenger) => [
                'type' => $passenger->type,
                'first_name' => $passenger->first_name,
                'last_name' => $passenger->last_name,
                'ticket_number' => $passenger->ticket_number,
            ])),
        ];
    }
}
