<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\OidcController;
use App\Models\OidcLogout;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces OpenID Connect back-channel logouts: a session started via OIDC
 * is ended as soon as the provider has reported a logout for its provider
 * session (sid), or for its user (sub) after the session started.
 *
 * Checked on the next request rather than by deleting session files, so it
 * works with every session driver and survives session id rotation.
 */
class EndOidcSessionsLoggedOutAtProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $issuer = $session->get(OidcController::SESSION_ISSUER);

        if (! is_string($issuer) || ! $this->loggedOutAtProvider(
            $issuer,
            $session->get(OidcController::SESSION_SID),
            $session->get(OidcController::SESSION_SUBJECT),
            (int) $session->get(OidcController::SESSION_LOGIN_AT),
        )) {
            return $next($request);
        }

        Log::info('[OIDC back-channel logout] Ending session of user '.Auth::id());

        Auth::logout();
        $session->invalidate();
        $session->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // No flash message on purpose: with OIDC as primary login the login
        // page then forwards straight to the provider's login.
        return redirect()->route('login');
    }

    private function loggedOutAtProvider(string $issuer, mixed $sid, mixed $subject, int $loginAt): bool
    {
        $sid = is_string($sid) && $sid !== '' ? $sid : null;
        $subject = is_string($subject) && $subject !== '' ? $subject : null;

        if ($sid === null && $subject === null) {
            return false;
        }

        return OidcLogout::where('issuer', $issuer)
            ->where(function ($query) use ($sid, $subject, $loginAt) {
                if ($sid !== null) {
                    $query->orWhere('sid', $sid);
                }

                if ($subject !== null) {
                    $query->orWhere(function ($query) use ($subject, $loginAt) {
                        $query->whereNull('sid')
                            ->where('subject', $subject)
                            ->where('logged_out_at', '>=', CarbonImmutable::createFromTimestamp($loginAt));
                    });
                }
            })
            ->exists();
    }
}
