<?php

namespace App\Http\Requests\Api\TravelerProfiles;

use Illuminate\Foundation\Http\FormRequest;

class StoreTravelerProfileRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'in:mr,mrs,ms,mx'],
            'gender' => ['nullable', 'string', 'in:m,f,x'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_expiry' => ['nullable', 'date', 'after:today'],
        ];
    }
}
