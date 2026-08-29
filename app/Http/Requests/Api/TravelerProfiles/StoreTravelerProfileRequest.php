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
            // Passport details are required so a booking can always be
            // ticketed with the provider without a follow-up data request.
            'date_of_birth' => ['required', 'date', 'after_or_equal:1900-01-01', 'before:today'],
            'nationality' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['required', 'string', 'max:50'],
            'passport_expiry' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addYears(20)->toDateString()],
        ];
    }
}
