<?php

namespace App\Http\Middleware;

use App\Actions\Scim\ReconcileScimUserActivationAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After every successful writing SCIM request (user or group created,
 * changed or deleted), brings the activation of SCIM-managed accounts in
 * line with their group memberships.
 */
class ReconcileScimUserActivation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe() && $response->isSuccessful()) {
            ReconcileScimUserActivationAction::run();
        }

        return $response;
    }
}
