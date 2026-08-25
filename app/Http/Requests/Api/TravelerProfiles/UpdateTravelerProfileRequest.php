<?php

namespace App\Http\Requests\Api\TravelerProfiles;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTravelerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('travelerProfile'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'required', 'date', 'before:today'],
            'nationality' => ['sometimes', 'nullable', 'string', 'size:2'],
            'passport_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'passport_expiry' => ['sometimes', 'nullable', 'date', 'after:today'],
        ];
    }
}
