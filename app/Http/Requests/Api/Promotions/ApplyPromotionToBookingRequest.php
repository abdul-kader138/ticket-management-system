<?php

namespace App\Http\Requests\Api\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class ApplyPromotionToBookingRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50'],
        ];
    }
}
