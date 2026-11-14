<?php

namespace App\Services\Oidc;

use App\Exceptions\OidcLoginFailed;
use App\Models\Setting;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relying-party side of the OpenID Connect authorization code flow used for
 * browser logins (as opposed to OidcTokenValidator, which only validates
 * bearer tokens presented to the API).
 *
 * Everything is driven by the admin settings: the provider is discovered
 * from its issuer URL, the code exchange is protected by PKCE (S256), and
 * the ID token is fully verified (signature against the provider's JWKS,
 * iss, aud/azp, exp and nonce) before any claim is trusted.
 */
class OidcLoginClient
{
    public const DEFAULT_SCOPES = 'openid profile email';

    public const DEFAULT_USERNAME_CLAIM = 'preferred_username';

    public const BACKCHANNEL_LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    private const CACHE_TTL_SECONDS = 3600;

    private const HTTP_TIMEOUT_SECONDS = 10;

    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(private ?Setting $settings = null)
    {
        $this->settings ??= Setting::getSettings();
    }

    /**
     * The issuer exactly as configured, minus any trailing slash.
     */
    public function issuer(): string
    {
        return rtrim((string) $this->settings->oidc_issuer, '/');
    }

    /**
     * The provider's discovery document, cached per issuer.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginFailed
     */
    public function discovery(bool $refresh = false): array
    {
        $issuer = $this->issuer();
        $cacheKey = 'oidc_login_discovery_'.md5($issuer);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($issuer) {
            try {
                $document = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($issuer.'/.well-known/openid-configuration')
                    ->throw()
                    ->json();
            } catch (\Throwable $e) {
                throw new OidcLoginFailed('Discovery request failed: '.$e->getMessage(), 0, $e);
            }

            if (! is_array($document)) {
                throw new OidcLoginFailed('Discovery document is not a JSON object');
            }

            // OIDC Discovery 1.0 §4.3: the issuer in the document MUST
            // match the URL the document was fetched from.
            if (($document['issuer'] ?? null) !== $issuer) {
                throw new OidcLoginFailed('Discovery issuer mismatch: expected '.$issuer.', got '.json_encode($document['issuer'] ?? null));
            }

            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
                if (empty($document[$required]) || ! is_string($document[$required])) {
                    throw new OidcLoginFailed('Discovery document has no '.$required);
                }
            }

            return $document;
        });
    }

    public function redirectUri(): string
    {
        return route('oidc.callback');
    }

    public function postLogoutRedirectUri(): string
    {
        return route('login');
    }

    /**
     * Build the URL that sends the browser to the provider's login page.
     *
     * @throws OidcLoginFailed
     */
    public function authorizationUrl(string $state, string $nonce, string $codeVerifier): string
    {
        $endpoint = $this->discovery()['authorization_endpoint'];

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->oidc_client_id,
            'redirect_uri' => $this->redirectUri(),
            'scope' => $this->scopes(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($codeVerifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Exchange the authorization code for tokens at the token endpoint.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginFailed
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
            'client_id' => $this->settings->oidc_client_id,
        ];

        $request = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->asForm()->acceptJson();

        // client_secret_basic is the spec default (RFC 6749 §2.3.1 requires
        // form-encoding both parts). Public clients send no secret and
        // rely on PKCE alone.
        if (($secret = $this->clientSecret()) !== null) {
            $request = $request->withBasicAuth(
                urlencode((string) $this->settings->oidc_client_id),
                urlencode($secret)
            );
        }

        try {
            $tokens = $request->post($this->discovery()['token_endpoint'], $payload)->throw()->json();
        } catch (OidcLoginFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OidcLoginFailed('Token request failed: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($tokens) || empty($tokens['id_token']) || ! is_string($tokens['id_token'])) {
            throw new OidcLoginFailed('Token response contains no id_token');
        }

        return $tokens;
    }

    /**
     * Verify the ID token and return its claims.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginFailed
     */
    public function validateIdToken(string $idToken, string $expectedNonce): array
    {
        $claims = $this->verifySignedToken($idToken, 'ID token');
        $audiences = (array) $claims['aud'];
        $clientId = (string) $this->settings->oidc_client_id;

        // OIDC Core §3.1.3.7 (4)/(5): with several audiences, or whenever an
        // azp is present, it must be this client.
        if ((count($audiences) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $clientId) {
            throw new OidcLoginFailed('ID token azp does not match the client id');
        }

        // php-jwt only checks exp when present; OIDC makes it mandatory.
        if (! isset($claims['exp'])) {
            throw new OidcLoginFailed('ID token has no exp claim');
        }

        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($expectedNonce, $claims['nonce'])) {
            throw new OidcLoginFailed('ID token nonce mismatch');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcLoginFailed('ID token has no subject');
        }

        return $claims;
    }

    /**
     * Verify a back-channel logout token (OIDC Back-Channel Logout 1.0 §2.6)
     * and return its claims.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginFailed
     */
    public function validateLogoutToken(string $logoutToken): array
    {
        $claims = $this->verifySignedToken($logoutToken, 'Logout token');

        if (! is_int($claims['iat'] ?? null)) {
            throw new OidcLoginFailed('Logout token has no iat claim');
        }

        $events = $claims['events'] ?? null;
        if (! is_array($events) || ! array_key_exists(self::BACKCHANNEL_LOGOUT_EVENT, $events)) {
            throw new OidcLoginFailed('Logout token has no back-channel logout event');
        }

        // Prohibited so that an ID token can never be replayed as a logout token.
        if (array_key_exists('nonce', $claims)) {
            throw new OidcLoginFailed('Logout token must not contain a nonce');
        }

        $hasSubject = is_string($claims['sub'] ?? null) && $claims['sub'] !== '';
        $hasSession = is_string($claims['sid'] ?? null) && $claims['sid'] !== '';
        if (! $hasSubject && ! $hasSession) {
            throw new OidcLoginFailed('Logout token has neither sub nor sid');
        }

        return $claims;
    }

    /**
     * Signature (against the provider JWKS), exp/nbf, iss and aud checks
     * shared by ID tokens and logout tokens.
     *
     * @return array<string, mixed>
     *
     * @throws OidcLoginFailed
     */
    private function verifySignedToken(string $jwt, string $kind): array
    {
        $discovery = $this->discovery();

        try {
            JWT::$leeway = self::CLOCK_SKEW_SECONDS;
            $decoded = JWT::decode($jwt, $this->signingKeys($jwt));
        } catch (OidcLoginFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OidcLoginFailed($kind.' signature/time validation failed: '.$e->getMessage(), 0, $e);
        }

        $claims = json_decode(json_encode($decoded), true);

        if (($claims['iss'] ?? null) !== $discovery['issuer']) {
            throw new OidcLoginFailed($kind.' issuer mismatch');
        }

        if (! in_array((string) $this->settings->oidc_client_id, (array) ($claims['aud'] ?? []), true)) {
            throw new OidcLoginFailed($kind.' audience does not contain the client id');
        }

        return $claims;
    }

    /**
     * Fetch additional claims from the userinfo endpoint (if the provider
     * has one) and merge them under the ID token claims. Claims from the
     * verified ID token always win, and the response is discarded unless
     * its subject matches (OIDC Core §5.3.2).
     *
     * @param  array<string, mixed>  $idTokenClaims
     * @return array<string, mixed>
     */
    public function mergeUserinfo(array $idTokenClaims, ?string $accessToken): array
    {
        $endpoint = $this->discovery()['userinfo_endpoint'] ?? null;

        if (empty($endpoint) || empty($accessToken)) {
            return $idTokenClaims;
        }

        try {
            $userinfo = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->withToken($accessToken)
                ->get($endpoint)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            Log::warning('[OIDC login] userinfo request failed: '.$e->getMessage());

            return $idTokenClaims;
        }

        if (! is_array($userinfo) || ($userinfo['sub'] ?? null) !== $idTokenClaims['sub']) {
            Log::warning('[OIDC login] Ignoring userinfo response with missing or mismatched sub');

            return $idTokenClaims;
        }

        return array_merge($userinfo, $idTokenClaims);
    }

    /**
     * The provider's RP-initiated logout URL, or null if it has none.
     */
    public function endSessionUrl(?string $idTokenHint): ?string
    {
        try {
            $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        } catch (OidcLoginFailed $e) {
            Log::warning('[OIDC login] Could not discover end_session_endpoint: '.$e->getMessage());

            return null;
        }

        if (empty($endpoint) || ! is_string($endpoint)) {
            return null;
        }

        $query = http_build_query(array_filter([
            'client_id' => $this->settings->oidc_client_id,
            'id_token_hint' => $idTokenHint,
            'post_logout_redirect_uri' => $this->postLogoutRedirectUri(),
        ]), '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    public function scopes(): string
    {
        $scopes = preg_split('/\s+/', trim((string) $this->settings->oidc_scopes), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($scopes)) {
            $scopes = explode(' ', self::DEFAULT_SCOPES);
        }

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return implode(' ', array_unique($scopes));
    }

    public function usernameClaim(): string
    {
        return trim((string) $this->settings->oidc_username_claim) ?: self::DEFAULT_USERNAME_CLAIM;
    }

    public static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Decrypted client secret, or null for a public client.
     */
    private function clientSecret(): ?string
    {
        if (empty($this->settings->oidc_client_secret)) {
            return null;
        }

        try {
            return Crypt::decrypt($this->settings->oidc_client_secret);
        } catch (DecryptException $e) {
            throw new OidcLoginFailed('Stored OIDC client secret cannot be decrypted (APP_KEY changed?)', 0, $e);
        }
    }

    /**
     * The provider's signing keys. Refetched once when the token names a key
     * id we have not seen, so provider key rotation needs no cache flush.
     *
     * @return array<string, \Firebase\JWT\Key>
     *
     * @throws OidcLoginFailed
     */
    private function signingKeys(string $idToken): array
    {
        $keys = $this->fetchSigningKeys(false);
        $kid = $this->unverifiedKeyId($idToken);

        if ($kid !== null && ! array_key_exists($kid, $keys)) {
            $keys = $this->fetchSigningKeys(true);
        }

        return $keys;
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     *
     * @throws OidcLoginFailed
     */
    private function fetchSigningKeys(bool $refresh): array
    {
        $jwksUri = $this->discovery()['jwks_uri'];
        $cacheKey = 'oidc_login_jwks_'.md5($jwksUri);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        try {
            $jwks = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($jwksUri) {
                return Http::timeout(self::HTTP_TIMEOUT_SECONDS)->acceptJson()->get($jwksUri)->throw()->json();
            });

            // Providers such as Keycloak also publish encryption keys
            // (use=enc, e.g. RSA-OAEP); only signing keys are relevant.
            $jwks['keys'] = array_values(array_filter(
                (array) ($jwks['keys'] ?? []),
                fn ($key) => is_array($key) && ($key['use'] ?? 'sig') === 'sig'
            ));

            // JWKS documents only carry public keys, so HS* / "none" can
            // never be selected (no algorithm confusion). RS256 is only the
            // fallback for keys that don't declare their own alg.
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $e) {
            throw new OidcLoginFailed('Could not load provider signing keys: '.$e->getMessage(), 0, $e);
        }
    }

    private function unverifiedKeyId(string $jwt): ?string
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0] ?? ''), true);

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }
}
