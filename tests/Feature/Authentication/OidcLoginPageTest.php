<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Tests\Support\FakesOidcProvider;
use Tests\TestCase;

class OidcLoginPageTest extends TestCase
{
    use FakesOidcProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // An install with zero users is treated as not set up yet.
        User::factory()->superuser()->create();

        $this->fakeOidcProvider();
    }

    public function test_login_page_offers_oidc_next_to_the_form_when_enabled()
    {
        $this->settings->enableOidc(['oidc_button_label' => 'Login with openDesk']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('oidc.login'), false)
            ->assertSee('Login with openDesk')
            ->assertSee('name="password"', false);
    }

    public function test_login_page_has_no_oidc_button_when_disabled()
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee(route('oidc.login'), false);
    }

    public function test_primary_login_redirects_straight_to_the_provider()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->get(route('login'))->assertRedirect(route('oidc.login'));
    }

    public function test_protected_pages_lead_unauthenticated_visitors_to_the_provider()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertRedirect(route('oidc.login'));
    }

    public function test_nosso_shows_the_local_login_form_as_escape_hatch()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->get(route('login', ['nosso' => 1]))
            ->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false);
    }

    public function test_nosaml_still_works_as_escape_hatch()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->get(route('login', ['nosaml' => 1]))
            ->assertOk()
            ->assertSee('name="password"', false);
    }

    public function test_local_login_still_works_through_the_escape_hatch()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);
        $user = User::factory()->create(['username' => 'breakglass', 'password' => bcrypt('correct-horse-battery')]);

        $this->post('/login', ['username' => 'breakglass', 'password' => 'correct-horse-battery']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_errors_are_shown_without_the_local_form_instead_of_looping()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->withSession(['error' => trans('auth/general.oidc_no_account')])
            ->get(route('login'))
            ->assertOk()
            ->assertSee(trans('auth/general.oidc_no_account'))
            ->assertSee(route('oidc.login'), false)
            ->assertDontSee('name="username"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_page_after_logout_has_no_local_form()
    {
        $this->settings->enableOidc(['oidc_forcelogin' => 1]);

        $this->withSession(['loggedout' => true, 'success' => trans('auth/message.logout.success')])
            ->get(route('login'))
            ->assertOk()
            ->assertSee(route('oidc.login'), false)
            ->assertDontSee('name="password"', false);
    }

    public function test_logout_ends_the_provider_session_when_enabled()
    {
        $this->settings->enableOidc(['oidc_slo' => 1]);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['oidc_id_token' => 'the-id-token'])
            ->post(route('logout.post'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($this->oidcIssuer.'/protocol/openid-connect/logout?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('the-id-token', $query['id_token_hint']);
        $this->assertSame('snipe-it', $query['client_id']);
        $this->assertSame(route('login'), $query['post_logout_redirect_uri']);
        $this->assertGuest();
        $this->assertFalse(session()->has('oidc_id_token'));
    }

    public function test_logout_stays_local_when_provider_logout_is_disabled()
    {
        $this->settings->enableOidc(['oidc_slo' => 0]);

        $this->actingAs(User::factory()->create())
            ->withSession(['oidc_id_token' => 'the-id-token'])
            ->post(route('logout.post'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_logout_of_a_local_session_stays_local()
    {
        $this->settings->enableOidc(['oidc_slo' => 1]);

        $this->actingAs(User::factory()->create())
            ->post(route('logout.post'))
            ->assertRedirect(route('login'));
    }
}
