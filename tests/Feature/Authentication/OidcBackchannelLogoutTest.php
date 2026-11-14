<?php

namespace Tests\Feature\Authentication;

use App\Models\OidcLogout;
use App\Models\User;
use Tests\Support\FakesOidcProvider;
use Tests\TestCase;

class OidcBackchannelLogoutTest extends TestCase
{
    use FakesOidcProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // An install with zero users is treated as not set up yet.
        User::factory()->superuser()->create();

        $this->settings->enableOidc();
        $this->fakeOidcProvider();
    }

    private function sendLogoutToken(string $logoutToken)
    {
        return $this->post(route('oidc.backchannel-logout'), ['logout_token' => $logoutToken]);
    }

    /**
     * Session as OidcController::callback() leaves it after a login.
     */
    private function oidcSession(int $loginAt, ?string $sid = 'provider-session-1'): array
    {
        return [
            'oidc_id_token' => 'id-token',
            'oidc_issuer' => $this->oidcIssuer,
            'oidc_subject' => 'subject-1',
            'oidc_sid' => $sid,
            'oidc_login_at' => $loginAt,
        ];
    }

    public function test_valid_logout_token_is_accepted_and_recorded()
    {
        $response = $this->sendLogoutToken($this->makeOidcLogoutToken())->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertDatabaseHas('oidc_logouts', [
            'issuer' => $this->oidcIssuer,
            'subject' => 'subject-1',
            'sid' => 'provider-session-1',
        ]);
    }

    public function test_endpoint_needs_no_session_or_csrf_token()
    {
        // The provider calls server-to-server: no cookie, no CSRF token.
        $this->withMiddleware()
            ->sendLogoutToken($this->makeOidcLogoutToken())
            ->assertOk();
    }

    public function test_logout_token_with_invalid_signature_is_rejected()
    {
        $this->sendLogoutToken($this->makeOidcLogoutToken([], signWithUnknownKey: true))
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_request']);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_logout_token_for_another_client_is_rejected()
    {
        $this->sendLogoutToken($this->makeOidcLogoutToken(['aud' => 'another-client']))->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_logout_token_from_another_issuer_is_rejected()
    {
        $this->sendLogoutToken($this->makeOidcLogoutToken(['iss' => 'https://evil.example.net/realms/x']))->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_logout_token_without_logout_event_is_rejected()
    {
        $this->sendLogoutToken($this->makeOidcLogoutToken(['events' => null]))->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_id_token_cannot_be_used_as_logout_token()
    {
        // ID tokens carry a nonce, which logout tokens must not.
        $this->sendLogoutToken($this->makeOidcLogoutToken(['nonce' => 'abc']))->assertStatus(400);
        $this->sendLogoutToken($this->makeOidcIdToken())->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_logout_token_without_sub_and_sid_is_rejected()
    {
        $this->sendLogoutToken($this->makeOidcLogoutToken(['sub' => null, 'sid' => null]))->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_logout_token_is_rejected_when_oidc_is_disabled()
    {
        $this->settings->set(['oidc_enabled' => 0]);

        $this->sendLogoutToken($this->makeOidcLogoutToken())->assertStatus(400);

        $this->assertSame(0, OidcLogout::count());
    }

    public function test_missing_logout_token_is_rejected()
    {
        $this->post(route('oidc.backchannel-logout'))->assertStatus(400);
    }

    public function test_session_is_ended_after_logout_of_its_provider_session()
    {
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken())->assertOk();

        $this->actingAs($user)
            ->withSession($this->oidcSession(time() - 60))
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_other_provider_sessions_of_the_same_user_stay_logged_in()
    {
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken(['sid' => 'provider-session-1']))->assertOk();

        $this->actingAs($user)
            ->withSession($this->oidcSession(time() - 60, sid: 'provider-session-2'))
            ->get(route('home'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_subject_logout_ends_every_session_started_before_it()
    {
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken(['sid' => null]))->assertOk();

        $this->actingAs($user)
            ->withSession($this->oidcSession(time() - 60, sid: 'any-session'))
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_subject_logout_does_not_end_sessions_started_afterwards()
    {
        // A replayed or late token must not end a newer login.
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken(['sid' => null, 'iat' => time() - 300]))->assertOk();

        $this->actingAs($user)
            ->withSession($this->oidcSession(time() - 60, sid: 'new-session'))
            ->get(route('home'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_local_sessions_are_not_affected()
    {
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken(['sid' => null]))->assertOk();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_ajax_requests_of_an_ended_session_get_401()
    {
        $user = User::factory()->superuser()->create();
        $this->sendLogoutToken($this->makeOidcLogoutToken())->assertOk();

        $this->actingAs($user)
            ->withSession($this->oidcSession(time() - 60))
            ->getJson(route('home'))
            ->assertUnauthorized();
    }

    public function test_full_flow_login_then_provider_logout_ends_the_session()
    {
        $user = User::factory()->create(['username' => 'jdoe']);
        $this->oidcIdToken = $this->makeOidcIdToken(['sid' => 'provider-session-1']);

        $this->withSession($this->oidcLoginSession())
            ->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'test-state']))
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('provider-session-1', session('oidc_sid'));

        $this->sendLogoutToken($this->makeOidcLogoutToken(['sid' => 'provider-session-1']))->assertOk();

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_old_logout_records_are_pruned()
    {
        OidcLogout::create([
            'issuer' => $this->oidcIssuer,
            'sid' => 'ancient',
            'logged_out_at' => now()->subMinutes((int) config('session.lifetime') + 10),
        ]);

        $this->sendLogoutToken($this->makeOidcLogoutToken())->assertOk();

        $this->assertDatabaseMissing('oidc_logouts', ['sid' => 'ancient']);
        $this->assertDatabaseHas('oidc_logouts', ['sid' => 'provider-session-1']);
    }
}
