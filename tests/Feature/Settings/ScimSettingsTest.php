<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Tests\TestCase;

class ScimSettingsTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.scim.index'))
            ->assertOk()
            ->assertSee(url('/scim/v2'));
    }

    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.scim.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.scim.save'), ['scim_existing_groups_only' => '1'])
            ->assertForbidden();
    }

    public function test_settings_tile_links_to_the_page()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.scim.index'), false);
    }

    public function test_settings_can_be_saved()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.scim.save'), [
                'scim_existing_groups_only' => '1',
                'scim_require_group_membership' => '1',
            ])
            ->assertRedirect(route('settings.scim.index'))
            ->assertSessionHasNoErrors();

        $setting = Setting::first();
        $this->assertTrue($setting->scim_existing_groups_only);
        $this->assertTrue($setting->scim_require_group_membership);
    }

    public function test_enabling_group_requirement_deactivates_scim_accounts_without_group_right_away()
    {
        $withoutGroup = User::factory()->create(['activated' => 1, 'scim_externalid' => 'ext-1', 'scim_active' => true]);
        $local = User::factory()->create(['activated' => 1]);

        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.scim.save'), ['scim_require_group_membership' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $withoutGroup->fresh()->activated);
        $this->assertSame(1, (int) $local->fresh()->activated);
    }
}
