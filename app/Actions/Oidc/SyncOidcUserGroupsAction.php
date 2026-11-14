<?php

namespace App\Actions\Oidc;

use App\Models\Group;
use App\Models\User;

/**
 * Makes a user's Snipe-IT group memberships mirror the groups the OpenID
 * Connect provider reports in the configured claim.
 *
 * Groups are matched by name. A leading slash is ignored, so Keycloak's
 * "full group path" format (/snipeit-admins) matches a Snipe-IT group
 * called "snipeit-admins". Provider groups without a Snipe-IT counterpart
 * are ignored; Snipe-IT groups the provider does not report are removed,
 * including when the claim is absent (the provider omits it for users
 * without groups, and keeping stale permissions would be the unsafe
 * failure mode). Changes are written to the user's history.
 */
class SyncOidcUserGroupsAction
{
    /**
     * @param  array<string, mixed>  $claims  Verified ID token (+ userinfo) claims.
     */
    public static function run(User $user, array $claims, string $groupsClaim): void
    {
        $raw = array_key_exists($groupsClaim, $claims) ? $claims[$groupsClaim] : data_get($claims, $groupsClaim);

        $names = collect(is_array($raw) ? $raw : [$raw])
            ->filter(fn ($name) => is_string($name))
            ->map(fn (string $name) => ltrim(trim($name), '/'))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values();

        $groupIds = $names->isEmpty()
            ? []
            : Group::whereIn('name', $names->all())->pluck('id')->all();

        $user->syncGroupsWithLogging($groupIds);
    }
}
