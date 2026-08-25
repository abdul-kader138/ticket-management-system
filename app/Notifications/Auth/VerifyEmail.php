<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;

/**
 * A thin customer-facing subclass of Laravel's own VerifyEmail — the base
 * class's default verificationUrl() (a signed `verification.verify` route,
 * see routes/api.php) already does exactly what we need. Kept as its own
 * class, not sent directly, so mail copy/branding can be customised here
 * later without touching App\Models\User.
 */
class VerifyEmail extends BaseVerifyEmail {}
