<?php

namespace App\Http\Requests\Api\Promotions;

use Illuminate\Foundation\Http\FormRequest;

class RedeemPromotionRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50'],
        ];
    }
}
