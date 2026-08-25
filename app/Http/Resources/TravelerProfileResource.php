<?php

namespace App\Http\Resources;

use App\Models\TravelerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TravelerProfile
 */
class TravelerProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            // Passport number is deliberately never returned once saved —
            // it's write-only from the API's point of view, same reasoning
            // as a card number never round-tripping back from a payment
            // gateway. has_passport lets the UI show "on file" instead.
            'has_passport' => filled($this->passport_number),
            'passport_expiry' => $this->passport_expiry?->toDateString(),
        ];
    }
}
