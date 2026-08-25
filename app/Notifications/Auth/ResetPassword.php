<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

/**
 * Unlike email verification, password reset has no backend page to link
 * to — the base notification's default resetUrl() points at a `password.
 * reset` named route we deliberately don't register, and instead sends the
 * customer straight to the frontend app, which reads token/email off the
 * query string and posts them to POST /api/v1/auth/reset-password.
 */
class ResetPassword extends BaseResetPassword
{
    protected function resetUrl($notifiable): string
    {
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return rtrim((string) config('app.frontend_url'), '/')."/reset-password?{$query}";
    }
}
