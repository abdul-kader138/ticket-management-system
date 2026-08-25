<?php

namespace App\Http\Requests\Api\Bookings;

use Illuminate\Foundation\Http\FormRequest;

class ApplyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->id === $this->route('booking')->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'change_offer_id' => ['required', 'string', 'max:100'],
            'gateway' => ['required', 'in:stripe,paypal'],
        ];
    }
}
