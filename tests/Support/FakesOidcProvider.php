<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Simulates a Keycloak-style OpenID Connect provider over Http::fake():
 * discovery, JWKS, token and userinfo endpoints, with ID tokens signed by a
 * real RSA key so the full signature validation path is exercised.
 */
trait FakesOidcProvider
{
    protected string $oidcIssuer = 'https://id.example.com/realms/opendesk';

    protected string $oidcClientId = 'snipe-it';

    /** ID token the fake token endpoint hands out. */
    protected ?string $oidcIdToken = null;

    /** @var array<string, mixed>|null Response of the fake userinfo endpoint. */
    protected ?array $oidcUserinfo = null;

    private static ?string $oidcPrivateKey = null;

    private static ?string $oidcOtherPrivateKey = null;

    protected function fakeOidcProvider(): void
    {
        Cache::flush();

        $base = $this->oidcIssuer.'/protocol/openid-connect';

        Http::fake([
            $this->oidcIssuer.'/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->oidcIssuer,
                'authorization_endpoint' => $base.'/auth',
                'token_endpoint' => $base.'/token',
                'userinfo_endpoint' => $base.'/userinfo',
                'jwks_uri' => $base.'/certs',
                'end_session_endpoint' => $base.'/logout',
            ]),
            $base.'/certs' => Http::response(['keys' => [
                $this->oidcJwk(self::oidcPrivateKey(), 'test-key'),
                // Keycloak also publishes an encryption key; it must be ignored.
                array_merge($this->oidcJwk(self::oidcOtherPrivateKey(), 'enc-key'), ['use' => 'enc', 'alg' => 'RSA-OAEP']),
            ]]),
            $base.'/token' => fn () => Http::response([
                'access_token' => 'access-token',
                'token_type' => 'Bearer',
                'id_token' => $this->oidcIdToken,
            ]),
            $base.'/userinfo' => fn () => Http::response($this->oidcUserinfo ?? ['sub' => 'subject-1']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims  Overrides for the default claims.
     */
    protected function makeOidcIdToken(array $claims = [], bool $signWithUnknownKey = false): string
    {
        $claims = array_merge([
            'iss' => $this->oidcIssuer,
            'aud' => $this->oidcClientId,
            'sub' => 'subject-1',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => 'test-nonce',
            'preferred_username' => 'jdoe',
        ], $claims);

        $key = $signWithUnknownKey ? self::oidcOtherPrivateKey() : self::oidcPrivateKey();

        return JWT::encode(array_filter($claims, fn ($value) => $value !== null), $key, 'RS256', 'test-key');
    }

    /**
     * A back-channel logout token as Keycloak sends it.
     *
     * @param  array<string, mixed>  $claims  Overrides; null removes a claim.
     */
    protected function makeOidcLogoutToken(array $claims = [], bool $signWithUnknownKey = false): string
    {
        $claims = array_merge([
            'iss' => $this->oidcIssuer,
            'aud' => $this->oidcClientId,
            'sub' => 'subject-1',
            'sid' => 'provider-session-1',
            'iat' => time(),
            'exp' => time() + 120,
            'jti' => 'logout-'.uniqid(),
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
        ], $claims);

        $key = $signWithUnknownKey ? self::oidcOtherPrivateKey() : self::oidcPrivateKey();

        return JWT::encode(array_filter($claims, fn ($value) => $value !== null), $key, 'RS256', 'test-key');
    }

    /**
     * Session state as OidcController::login() leaves it.
     *
     * @return array<string, string>
     */
    protected function oidcLoginSession(): array
    {
        return [
            'oidc_login_state' => 'test-state',
            'oidc_login_nonce' => 'test-nonce',
            'oidc_login_code_verifier' => 'test-code-verifier',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function oidcJwk(string $privateKey, string $kid): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));

        return [
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ];
    }

    private static function oidcPrivateKey(): string
    {
        return self::$oidcPrivateKey ??= self::generateOidcKey();
    }

    private static function oidcOtherPrivateKey(): string
    {
        return self::$oidcOtherPrivateKey ??= self::generateOidcKey();
    }

    private static function generateOidcKey(): string
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $pem);

        return $pem;
    }
}
