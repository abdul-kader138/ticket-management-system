<?php

namespace App\Http\Requests\Api\Bookings;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_code' => ['required', 'string', 'max:50'],
            'offer_id' => ['required', 'string', 'max:100'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.traveler_profile_id' => ['required', 'integer'],
            'passengers.*.type' => ['required', 'in:adult,child,infant'],
        ];
    }
}
