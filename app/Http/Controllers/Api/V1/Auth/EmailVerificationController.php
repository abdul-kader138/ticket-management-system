<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class EmailVerificationController extends Controller
{
    /**
     * The link in the verification email is a plain browser navigation
     * (see App\Notifications\Auth\VerifyEmail), not an XHR call from the
     * SPA, so this ends in a redirect back to the frontend rather than a
     * JSON response — nothing in this app renders that page itself.
     * EmailVerificationRequest::authorize() enforces the signed-hash check
     * against the currently authenticated user (route is behind
     * auth:sanctum — see routes/api.php), matching Sanctum's own SPA
     * email-verification convention.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $alreadyVerified = $request->user()->hasVerifiedEmail();

        $request->fulfill();

        return redirect()->away(
            rtrim((string) config('app.frontend_url'), '/').'/email-verified?status='.($alreadyVerified ? 'already-verified' : 'verified')
        );
    }
}
