<?php

use App\Http\Middleware\CheckUserIsActivated;
use App\Http\Middleware\EnforceApiUserAgent;
use App\Http\Middleware\ReconcileScimUserActivation;
use ArieTimmerman\Laravel\SCIMServer\RouteProvider as SCIMRouteProvider;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SCIM Routes
|--------------------------------------------------------------------------
|
| These are the routes that we have to explicitly inject from the
| laravel-scim-server project, which gives Snipe-IT SCIM support
|
*/

SCIMRouteProvider::publicRoutes(); // Make sure to load public routes *FIRST*

// No API rate limit here: identity providers push full syncs (one request
// per user and group) and clients such as the Univention Nubus SCIM client
// don't retry on HTTP 429. These routes are superuser-token only.
Route::middleware(['auth:api', CheckUserIsActivated::class, EnforceApiUserAgent::class.':allow_blank_user_agent', 'authorize:superadmin', ReconcileScimUserActivation::class])->group(function () {
    SCIMRouteProvider::routes(
        [
            /*
             * If we leave public_routes as 'true', the public routes will load *now* and
             * be jammed into the same middleware that these private routes are loaded
             * with. That's bad, because these routes are *supposed* to be public.
             *
             * We loaded them a few lines above, *first*, otherwise the various
             * fallback routes in the library defined within these *secured* routes
             * will "take over" the above routes - and then you will end up losing
             * like 4 hours of your life trying to figure out why the public routes
             * aren't quite working right. Ask me how I know (BMW, 3/19/2022)
             */
            'public_routes' => false,
        ]
    );

    SCIMRouteProvider::meRoutes();
}); // ->can('superuser');
