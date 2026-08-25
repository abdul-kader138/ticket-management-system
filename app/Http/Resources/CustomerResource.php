<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class CustomerResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'marketing_opt_in' => $this->marketing_opt_in,
            'email_verified' => $this->hasVerifiedEmail(),
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'avatar_url' => $this->getFilamentAvatarUrl(),
            'referral_code' => $this->referralCode(),
            'created_at' => $this->created_at,
        ];
    }
}
