<?php

namespace App\Http\Requests\Api\Bookings;

use Illuminate\Foundation\Http\FormRequest;

class SearchChangeOffersRequest extends FormRequest
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
            'legs' => ['required', 'array', 'min:1'],
            'legs.*.from' => ['required', 'string', 'max:150'],
            'legs.*.to' => ['required', 'string', 'max:150'],
            'legs.*.date' => ['required', 'date'],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
        ];
    }
}
