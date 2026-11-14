<?php

namespace Tests\Feature\Privacy;

use App\Models\Setting;
use App\Models\User;
use Tests\TestCase;

class PrivacySettingsTest extends TestCase
{
    public function test_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.privacy.index'))
            ->assertOk()
            ->assertSee(trans('admin/settings/general.privacy_truncate_ip_addresses'));
    }

    public function test_requires_permission()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.privacy.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.privacy.save'), ['privacy_ip_retention_days' => 30])
            ->assertForbidden();
    }

    public function test_settings_tile_links_to_the_page()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.privacy.index'), false);
    }

    public function test_settings_can_be_saved_and_cleared()
    {
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->post(route('settings.privacy.save'), [
                'privacy_truncate_ip_addresses' => '1',
                'privacy_ip_retention_days' => '90',
                'privacy_login_attempts_retention_days' => '30',
                'privacy_anonymize_deleted_users_days' => '365',
            ])
            ->assertRedirect(route('settings.privacy.index'))
            ->assertSessionHasNoErrors();

        $setting = Setting::first();
        $this->assertTrue($setting->privacy_truncate_ip_addresses);
        $this->assertSame(90, $setting->privacy_ip_retention_days);
        $this->assertSame(30, $setting->privacy_login_attempts_retention_days);
        $this->assertSame(365, $setting->privacy_anonymize_deleted_users_days);

        $this->actingAs($admin)
            ->post(route('settings.privacy.save'), [
                'privacy_ip_retention_days' => '',
                'privacy_login_attempts_retention_days' => '',
                'privacy_anonymize_deleted_users_days' => '',
            ])
            ->assertSessionHasNoErrors();

        $setting->refresh();
        $this->assertFalse($setting->privacy_truncate_ip_addresses);
        $this->assertNull($setting->privacy_ip_retention_days);
        $this->assertNull($setting->privacy_login_attempts_retention_days);
        $this->assertNull($setting->privacy_anonymize_deleted_users_days);
    }

    public function test_retention_periods_must_be_positive_whole_days()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('settings.privacy.save'), [
                'privacy_ip_retention_days' => '0',
                'privacy_login_attempts_retention_days' => 'abc',
            ])
            ->assertSessionHasErrors(['privacy_ip_retention_days', 'privacy_login_attempts_retention_days']);
    }
}
