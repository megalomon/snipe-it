<?php

namespace App\Actions\Oidc;

use App\Models\OidcLogout;
use Carbon\CarbonImmutable;

/**
 * Records a verified back-channel logout so that the matching Snipe-IT
 * sessions are ended on their next request.
 *
 * The logout time is the token's iat, not "now": a replayed token can then
 * never end a session that was started after the original logout.
 */
class RecordOidcLogoutAction
{
    /**
     * @param  array<string, mixed>  $claims  Verified logout token claims.
     */
    public static function run(array $claims): OidcLogout
    {
        $sid = is_string($claims['sid'] ?? null) && $claims['sid'] !== '' ? $claims['sid'] : null;
        $subject = is_string($claims['sub'] ?? null) && $claims['sub'] !== '' ? $claims['sub'] : null;

        $logout = OidcLogout::create([
            'issuer' => $claims['iss'],
            'subject' => $subject,
            'sid' => $sid,
            'logged_out_at' => CarbonImmutable::createFromTimestamp($claims['iat']),
        ]);

        // A session can't outlive the session lifetime, so neither needs
        // the record of its logout.
        OidcLogout::where('logged_out_at', '<', now()->subMinutes((int) config('session.lifetime')))->delete();

        return $logout;
    }
}
