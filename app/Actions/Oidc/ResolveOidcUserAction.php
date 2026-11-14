<?php

namespace App\Actions\Oidc;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Maps the verified claims of an OpenID Connect login to a Snipe-IT user.
 *
 * Lookup order:
 *  1. the (iss, sub) pair pinned on a previous login, which survives
 *     username changes at the provider;
 *  2. the configured username claim, after which (iss, sub) is pinned.
 *
 * Deactivated users are never logged in. When user provisioning is enabled,
 * unknown users are created and first name, last name and email are kept
 * in sync with the provider on every login.
 */
class ResolveOidcUserAction
{
    /**
     * @param  array<string, mixed>  $claims  Verified ID token (+ userinfo) claims.
     */
    public static function run(array $claims, Setting $settings, string $usernameClaim): ?User
    {
        $issuer = (string) $claims['iss'];
        $subject = (string) $claims['sub'];

        $user = User::where('oidc_issuer', '=', $issuer)
            ->where('oidc_subject', '=', $subject)
            ->first();

        if ($user === null) {
            $username = self::claim($claims, $usernameClaim);

            if ($username === null) {
                Log::warning('[OIDC login] ID token carries no usable username claim', ['claim' => $usernameClaim]);

                return null;
            }

            $user = User::verifyExactUsernameMatch(User::where('username', '=', $username)->first(), $username);

            if ($user !== null && $user->oidc_issuer === $issuer && ! empty($user->oidc_subject)) {
                // The account is already bound to a *different* subject at
                // this provider: the username was reassigned there. Never
                // hand the old account to the new identity.
                Log::warning('[OIDC login] Username is bound to another subject at this issuer', ['username' => $username]);

                return null;
            }

            if ($user === null) {
                if (! $settings->oidc_provision_users) {
                    Log::info('[OIDC login] No Snipe-IT user for OIDC identity and provisioning is disabled', ['username' => $username]);

                    return null;
                }

                return self::createUser($claims, $username, $issuer, $subject);
            }

            if (empty($user->oidc_issuer)) {
                $user->oidc_issuer = $issuer;
                $user->oidc_subject = $subject;
                $user->saveQuietly();
            }
        }

        if (! $user->activated) {
            Log::info('[OIDC login] Snipe-IT user is deactivated', ['username' => $user->username]);

            return null;
        }

        if ($settings->oidc_provision_users) {
            self::updateProfile($user, $claims);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function createUser(array $claims, string $username, string $issuer, string $subject): ?User
    {
        $user = new User;
        $user->username = $username;
        $user->first_name = self::claim($claims, 'given_name') ?? $username;
        $user->last_name = self::claim($claims, 'family_name');
        $user->email = self::claim($claims, 'email');
        $user->password = $user->noPassword();
        $user->locale = app()->getLocale();
        $user->activated = 1;
        $user->oidc_issuer = $issuer;
        $user->oidc_subject = $subject;
        $user->notes = 'Created on first login via OpenID Connect';

        if (! $user->save()) {
            Log::warning('[OIDC login] Could not provision user', ['username' => $username, 'errors' => $user->getErrors()->toArray()]);

            return null;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function updateProfile(User $user, array $claims): void
    {
        $profile = array_filter([
            'first_name' => self::claim($claims, 'given_name'),
            'last_name' => self::claim($claims, 'family_name'),
            'email' => self::claim($claims, 'email'),
        ], fn ($value) => $value !== null);

        foreach ($profile as $attribute => $value) {
            $user->{$attribute} = $value;
        }

        if ($user->isDirty() && ! $user->save()) {
            Log::warning('[OIDC login] Could not update user profile from claims', ['username' => $user->username, 'errors' => $user->getErrors()->toArray()]);
            $user->refresh();
        }
    }

    /**
     * A non-empty string claim, looked up literally first (claim names may
     * contain dots, e.g. namespaced URLs) and then as a dotted path.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function claim(array $claims, string $name): ?string
    {
        $value = array_key_exists($name, $claims) ? $claims[$name] : data_get($claims, $name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
