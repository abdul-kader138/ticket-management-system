<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\CustomerResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    /**
     * Self-registered accounts get no Filament role, which is what already
     * keeps them out of the admin panel (User::canAccessPanel() requires
     * one) — unlike App\Filament\Auth\Register, which assigns 'panel_user'
     * for the opposite reason.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $referrer = filled($data['referral_code'] ?? null) ? User::findByReferralCode($data['referral_code']) : null;

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            'password' => $data['password'],
        ]);

        if ($referrer) {
            $user->forceFill(['referrer_id' => $referrer->id])->save();
        }

        $user->sendEmailVerificationNotification();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new CustomerResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
