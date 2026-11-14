<?php

namespace Tests\Feature\Authentication;

use App\Models\Group;
use App\Models\User;
use App\Services\Oidc\OidcLoginClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesOidcProvider;
use Tests\TestCase;

class OidcLoginTest extends TestCase
{
    use FakesOidcProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // An install with zero users is treated as not set up yet and
        // every request is redirected to /setup.
        User::factory()->superuser()->create(['username' => 'existing-admin']);

        $this->settings->enableOidc();
        $this->fakeOidcProvider();
    }

    private function completeOidcLogin(array $claims = [], array $query = [], bool $signWithUnknownKey = false)
    {
        $this->oidcIdToken = $this->makeOidcIdToken($claims, $signWithUnknownKey);

        return $this->withSession($this->oidcLoginSession())
            ->get(route('oidc.callback', array_merge(['code' => 'auth-code', 'state' => 'test-state'], $query)));
    }

    public function test_login_redirects_to_provider_with_state_nonce_and_pkce()
    {
        $response = $this->get(route('oidc.login'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($this->oidcIssuer.'/protocol/openid-connect/auth?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('snipe-it', $query['client_id']);
        $this->assertSame(route('oidc.callback'), $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('oidc_login_state'), $query['state']);
        $this->assertSame(session('oidc_login_nonce'), $query['nonce']);
        $this->assertSame(OidcLoginClient::codeChallenge(session('oidc_login_code_verifier')), $query['code_challenge']);
    }

    public function test_login_is_refused_when_oidc_is_disabled()
    {
        $this->settings->set(['oidc_enabled' => 0]);

        $this->get(route('oidc.login'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
    }

    public function test_existing_user_is_logged_in_and_bound_to_the_subject()
    {
        $user = User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin()->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame($this->oidcIssuer, $user->oidc_issuer);
        $this->assertSame('subject-1', $user->oidc_subject);
        $this->assertNotNull($user->last_login);
    }

    public function test_code_is_exchanged_with_pkce_verifier_and_client_secret()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin();

        Http::assertSent(function (Request $request) {
            return $request->url() === $this->oidcIssuer.'/protocol/openid-connect/token'
                && $request['code'] === 'auth-code'
                && $request['code_verifier'] === 'test-code-verifier'
                && $request['redirect_uri'] === route('oidc.callback')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('snipe-it:client-secret'));
        });
    }

    public function test_bound_user_is_found_by_subject_after_a_username_change_at_the_provider()
    {
        $user = User::factory()->create([
            'username' => 'jdoe',
            'oidc_issuer' => $this->oidcIssuer,
            'oidc_subject' => 'subject-1',
        ]);

        $this->completeOidcLogin(['preferred_username' => 'john.doe']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_is_sent_to_the_page_they_originally_requested()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->withSession(['url.intended' => url('/hardware')]);

        $this->completeOidcLogin()->assertRedirect(url('/hardware'));
    }

    public function test_callback_with_mismatched_state_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin([], ['state' => 'forged-state'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', trans('auth/general.oidc_login_failed'));

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
    }

    public function test_callback_without_a_started_login_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);
        $this->oidcIdToken = $this->makeOidcIdToken();

        $this->get(route('oidc.callback', ['code' => 'auth-code', 'state' => 'test-state']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_provider_error_is_rejected()
    {
        $this->withSession($this->oidcLoginSession())
            ->get(route('oidc.callback', ['error' => 'access_denied', 'state' => 'test-state']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_id_token_with_wrong_nonce_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin(['nonce' => 'other-nonce'])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_id_token_for_another_client_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin(['aud' => 'another-client'])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_id_token_from_another_issuer_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin(['iss' => 'https://evil.example.net/realms/opendesk'])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_expired_id_token_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin(['exp' => time() - 3600, 'iat' => time() - 7200])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_id_token_with_invalid_signature_is_rejected()
    {
        User::factory()->create(['username' => 'jdoe']);

        $this->completeOidcLogin([], [], signWithUnknownKey: true)->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_unknown_user_is_rejected_when_provisioning_is_disabled()
    {
        $this->completeOidcLogin()
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', trans('auth/general.oidc_no_account'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['username' => 'jdoe']);
    }

    public function test_unknown_user_is_created_when_provisioning_is_enabled()
    {
        $this->settings->set(['oidc_provision_users' => 1]);

        $this->completeOidcLogin([
            'given_name' => 'Jane',
            'family_name' => 'Doe',
            'email' => 'jane.doe@example.com',
        ])->assertRedirect('/');

        $user = User::where('username', 'jdoe')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Doe', $user->last_name);
        $this->assertSame('jane.doe@example.com', $user->email);
        $this->assertSame('subject-1', $user->oidc_subject);
    }

    public function test_profile_is_updated_from_claims_when_provisioning_is_enabled()
    {
        $this->settings->set(['oidc_provision_users' => 1]);
        $user = User::factory()->create(['username' => 'jdoe', 'first_name' => 'Old', 'email' => 'old@example.com']);

        $this->completeOidcLogin(['given_name' => 'Jane', 'email' => 'jane.doe@example.com']);

        $user->refresh();
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('jane.doe@example.com', $user->email);
    }

    public function test_deactivated_user_cannot_log_in()
    {
        $this->settings->set(['oidc_provision_users' => 1]);
        User::factory()->create(['username' => 'jdoe', 'activated' => 0]);

        $this->completeOidcLogin()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_account_bound_to_another_subject_is_not_taken_over()
    {
        $this->settings->set(['oidc_provision_users' => 1]);
        User::factory()->create([
            'username' => 'jdoe',
            'oidc_issuer' => $this->oidcIssuer,
            'oidc_subject' => 'previous-owner',
        ]);

        $this->completeOidcLogin(['sub' => 'new-owner'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(1, User::where('username', 'jdoe')->count());
    }

    public function test_groups_are_synced_from_the_groups_claim()
    {
        $this->settings->set(['oidc_groups_claim' => 'groups']);
        $admins = Group::factory()->create(['name' => 'snipeit-admins']);
        $helpdesk = Group::factory()->create(['name' => 'helpdesk']);
        $legacy = Group::factory()->create(['name' => 'legacy']);
        $user = User::factory()->create(['username' => 'jdoe']);
        $user->groups()->attach([$admins->id, $legacy->id]);

        $this->completeOidcLogin(['groups' => ['/snipeit-admins', 'helpdesk', 'not-in-snipe-it']]);

        $this->assertEqualsCanonicalizing(
            [$admins->id, $helpdesk->id],
            $user->fresh()->groups()->pluck('permission_groups.id')->all()
        );
    }

    public function test_all_groups_are_removed_when_the_provider_reports_none()
    {
        $this->settings->set(['oidc_groups_claim' => 'groups']);
        $user = User::factory()->create(['username' => 'jdoe']);
        $user->groups()->attach(Group::factory()->create()->id);

        $this->completeOidcLogin();

        $this->assertCount(0, $user->fresh()->groups);
    }

    public function test_groups_are_left_alone_when_no_groups_claim_is_configured()
    {
        $user = User::factory()->create(['username' => 'jdoe']);
        $group = Group::factory()->create(['name' => 'helpdesk']);
        $user->groups()->attach($group->id);

        $this->completeOidcLogin(['groups' => []]);

        $this->assertSame([$group->id], $user->fresh()->groups()->pluck('permission_groups.id')->all());
    }

    public function test_groups_are_read_from_userinfo_when_missing_in_the_id_token()
    {
        $this->settings->set(['oidc_groups_claim' => 'groups']);
        $helpdesk = Group::factory()->create(['name' => 'helpdesk']);
        $user = User::factory()->create(['username' => 'jdoe']);
        $this->oidcUserinfo = ['sub' => 'subject-1', 'groups' => ['helpdesk']];

        $this->completeOidcLogin();

        $this->assertSame([$helpdesk->id], $user->fresh()->groups()->pluck('permission_groups.id')->all());
    }

    public function test_userinfo_for_another_subject_is_ignored()
    {
        $this->settings->set(['oidc_groups_claim' => 'groups']);
        Group::factory()->create(['name' => 'helpdesk']);
        $user = User::factory()->create(['username' => 'jdoe']);
        $this->oidcUserinfo = ['sub' => 'someone-else', 'groups' => ['helpdesk']];

        $this->completeOidcLogin();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $user->fresh()->groups);
    }
}
