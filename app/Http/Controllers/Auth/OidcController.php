<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Oidc\RecordOidcLogoutAction;
use App\Actions\Oidc\ResolveOidcUserAction;
use App\Actions\Oidc\SyncOidcUserGroupsAction;
use App\Exceptions\OidcLoginFailed;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Oidc\OidcLoginClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser login via OpenID Connect (authorization code flow with PKCE).
 */
class OidcController extends Controller
{
    public const SESSION_STATE = 'oidc_login_state';

    public const SESSION_NONCE = 'oidc_login_nonce';

    public const SESSION_CODE_VERIFIER = 'oidc_login_code_verifier';

    public const SESSION_ID_TOKEN = 'oidc_id_token';

    public const SESSION_ISSUER = 'oidc_issuer';

    public const SESSION_SUBJECT = 'oidc_subject';

    public const SESSION_SID = 'oidc_sid';

    public const SESSION_LOGIN_AT = 'oidc_login_at';

    /**
     * Every session key that belongs to an OIDC-authenticated session.
     *
     * @var array<int, string>
     */
    public const SESSION_KEYS = [
        self::SESSION_ID_TOKEN,
        self::SESSION_ISSUER,
        self::SESSION_SUBJECT,
        self::SESSION_SID,
        self::SESSION_LOGIN_AT,
    ];

    /**
     * Send the browser to the provider's login page.
     */
    public function login(Request $request): RedirectResponse
    {
        $settings = Setting::getSettings();

        if (! $settings->isOidcLoginEnabled()) {
            return $this->failed();
        }

        if (Auth::check()) {
            return Helper::safeIntended('/');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);

        try {
            $url = (new OidcLoginClient($settings))->authorizationUrl($state, $nonce, $codeVerifier);
        } catch (OidcLoginFailed $e) {
            Log::error('[OIDC login] Could not start login: '.$e->getMessage());

            return $this->failed();
        }

        $request->session()->put([
            self::SESSION_STATE => $state,
            self::SESSION_NONCE => $nonce,
            self::SESSION_CODE_VERIFIER => $codeVerifier,
        ]);

        return redirect()->away($url);
    }

    /**
     * Handle the provider's redirect back to Snipe-IT.
     */
    public function callback(Request $request): RedirectResponse
    {
        $settings = Setting::getSettings();

        // Single-use: pull, so a replayed callback URL can never succeed.
        $expectedState = $request->session()->pull(self::SESSION_STATE);
        $nonce = $request->session()->pull(self::SESSION_NONCE);
        $codeVerifier = $request->session()->pull(self::SESSION_CODE_VERIFIER);

        if (! $settings->isOidcLoginEnabled()) {
            return $this->failed();
        }

        if ($request->filled('error')) {
            Log::info('[OIDC login] Provider returned an error', ['error' => (string) $request->input('error')]);

            return $this->failed();
        }

        $state = $request->input('state');
        $code = $request->input('code');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)
            || ! is_string($code) || $code === '' || ! is_string($nonce) || ! is_string($codeVerifier)) {
            Log::warning('[OIDC login] Callback with missing or mismatched state');

            return $this->failed();
        }

        $client = new OidcLoginClient($settings);

        try {
            $tokens = $client->exchangeCode($code, $codeVerifier);
            $claims = $client->validateIdToken($tokens['id_token'], $nonce);
            $claims = $client->mergeUserinfo($claims, $tokens['access_token'] ?? null);
        } catch (OidcLoginFailed $e) {
            Log::warning('[OIDC login] '.$e->getMessage());

            return $this->failed();
        }

        $user = ResolveOidcUserAction::run($claims, $settings, $client->usernameClaim());

        if ($user === null) {
            return $this->failed(trans('auth/general.oidc_no_account'));
        }

        if (! empty($settings->oidc_groups_claim)) {
            SyncOidcUserGroupsAction::run($user, $claims, trim($settings->oidc_groups_claim));
        }

        Auth::login($user);

        $user->last_login = \Carbon::now();
        $user->saveQuietly();

        // Remembered for RP-initiated logout (id_token_hint) and so a
        // back-channel logout from the provider can find this session.
        $request->session()->put([
            self::SESSION_ID_TOKEN => $tokens['id_token'],
            self::SESSION_ISSUER => $claims['iss'],
            self::SESSION_SUBJECT => $claims['sub'],
            self::SESSION_SID => is_string($claims['sid'] ?? null) ? $claims['sid'] : null,
            self::SESSION_LOGIN_AT => time(),
        ]);

        return Helper::safeIntended('/');
    }

    /**
     * OpenID Connect Back-Channel Logout 1.0 endpoint: the provider calls
     * this server-to-server when a user logs out there (or an admin ends
     * the user's sessions), and the matching Snipe-IT sessions are ended.
     * Runs without session or CSRF protection (see NoSessionStore and
     * VerifyCsrfToken); the signed logout token is the only credential.
     */
    public function backchannelLogout(Request $request): Response
    {
        $settings = Setting::getSettings();
        $logoutToken = $request->input('logout_token');

        if (! $settings->isOidcLoginEnabled() || ! is_string($logoutToken) || $logoutToken === '') {
            return $this->backchannelLogoutRejected();
        }

        try {
            $claims = (new OidcLoginClient($settings))->validateLogoutToken($logoutToken);
        } catch (OidcLoginFailed $e) {
            Log::warning('[OIDC back-channel logout] '.$e->getMessage());

            return $this->backchannelLogoutRejected();
        }

        RecordOidcLogoutAction::run($claims);

        return response('', 200)->header('Cache-Control', 'no-store');
    }

    private function backchannelLogoutRejected(): Response
    {
        return response()->json(['error' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
    }

    private function failed(?string $message = null): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message ?? trans('auth/general.oidc_login_failed'));
    }
}
