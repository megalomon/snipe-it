<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesOidcProvider;
use Tests\TestCase;

class OidcSettingsTest extends TestCase
{
    use FakesOidcProvider;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'oidc_enabled' => '1',
            'oidc_issuer' => 'https://id.example.com/realms/opendesk/',
            'oidc_client_id' => 'snipe-it',
            'oidc_client_secret' => 'top-secret',
            'oidc_scopes' => 'openid profile email groups',
            'oidc_username_claim' => 'preferred_username',
            'oidc_groups_claim' => 'groups',
            'oidc_button_label' => 'Login with openDesk',
            'oidc_forcelogin' => '1',
            'oidc_slo' => '1',
            'oidc_provision_users' => '1',
        ], $overrides);
    }

    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.oidc.index'))
            ->assertOk()
            ->assertSee(route('oidc.callback'));
    }

    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.oidc.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.oidc.save'), $this->validPayload())
            ->assertForbidden();
    }

    public function test_settings_tile_links_to_the_page()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.oidc.index'), false);
    }

    public function test_settings_can_be_saved()
    {
        $this->fakeOidcProvider();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload())
            ->assertRedirect(route('settings.oidc.index'))
            ->assertSessionHasNoErrors();

        $setting = Setting::first();
        $this->assertTrue($setting->oidc_enabled);
        $this->assertSame('https://id.example.com/realms/opendesk', $setting->oidc_issuer);
        $this->assertSame('snipe-it', $setting->oidc_client_id);
        $this->assertSame('groups', $setting->oidc_groups_claim);
        $this->assertTrue($setting->oidc_forcelogin);
        $this->assertTrue($setting->oidc_slo);
        $this->assertTrue($setting->oidc_provision_users);
        $this->assertNotSame('top-secret', $setting->oidc_client_secret);
        $this->assertSame('top-secret', Crypt::decrypt($setting->oidc_client_secret));
    }

    public function test_empty_secret_field_keeps_the_stored_secret()
    {
        $this->fakeOidcProvider();
        $this->settings->enableOidc(['oidc_client_secret' => Crypt::encrypt('stored-secret')]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload(['oidc_client_secret' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('stored-secret', Crypt::decrypt(Setting::first()->oidc_client_secret));
    }

    public function test_stored_secret_can_be_removed()
    {
        $this->fakeOidcProvider();
        $this->settings->enableOidc();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload(['oidc_client_secret' => '', 'oidc_clear_client_secret' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Setting::first()->oidc_client_secret);
    }

    public function test_stored_secret_is_never_rendered()
    {
        $this->settings->enableOidc(['oidc_client_secret' => Crypt::encrypt('stored-secret')]);
        $encrypted = Setting::first()->oidc_client_secret;

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.oidc.index'))
            ->assertOk()
            ->assertDontSee('stored-secret')
            ->assertDontSee($encrypted);
    }

    public function test_issuer_and_client_id_are_required_when_enabled()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload(['oidc_issuer' => '', 'oidc_client_id' => '']))
            ->assertSessionHasErrors(['oidc_issuer', 'oidc_client_id']);

        $this->assertFalse((bool) Setting::first()->oidc_enabled);
    }

    public function test_cannot_enable_with_an_unreachable_issuer()
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload())
            ->assertSessionHasErrors(['oidc_issuer' => trans('admin/settings/general.oidc_discovery_failed')]);

        $this->assertFalse((bool) Setting::first()->oidc_enabled);
    }

    public function test_can_be_disabled_without_contacting_the_issuer()
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        $this->settings->enableOidc();

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.oidc.save'), $this->validPayload(['oidc_enabled' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Setting::first()->oidc_enabled);
    }
}
