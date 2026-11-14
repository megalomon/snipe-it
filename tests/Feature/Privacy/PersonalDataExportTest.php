<?php

namespace Tests\Feature\Privacy;

use App\Enums\ActionType;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonalDataExportTest extends TestCase
{
    private function personWithData(): User
    {
        $user = User::factory()->create([
            'username' => 'jane.doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+49 261 123456',
            'two_factor_secret' => 'SECRET',
        ]);

        Asset::factory()->assignedToUser($user)->create(['asset_tag' => 'NB-0001']);
        DB::table('login_attempts')->insert([
            ['username' => 'jane.doe', 'remote_ip' => '203.0.113.5', 'user_agent' => 'Firefox', 'successful' => 1, 'created_at' => now()],
            ['username' => 'someone.else', 'remote_ip' => '203.0.113.6', 'user_agent' => 'Chrome', 'successful' => 1, 'created_at' => now()],
        ]);

        return $user;
    }

    public function test_superuser_gets_everything_about_a_user_without_secrets()
    {
        $user = $this->personWithData();
        $other = User::factory()->create();
        DB::table('action_logs')->insert([
            'action_type' => ActionType::Update->value,
            'item_type' => User::class,
            'item_id' => $other->id,
            'created_by' => $user->id,
            'note' => 'Note about a colleague',
            'log_meta' => json_encode(['phone' => ['old' => '111', 'new' => '222']]),
            'remote_ip' => '203.0.113.5',
            'created_at' => now(),
        ]);

        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.users.personal-data', $user))
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('profile.username', 'jane.doe')
            ->assertJsonPath('profile.email', 'jane.doe@example.com')
            ->assertJsonPath('profile.phone', '+49 261 123456')
            ->assertJsonPath('profile.has_two_factor_secret', true)
            ->assertJsonPath('assigned_items.assets.0.asset_tag', 'NB-0001')
            ->assertJsonCount(1, 'login_attempts')
            ->assertJsonPath('login_attempts.0.remote_ip', '203.0.113.5');

        $profile = $response->json('profile');
        $this->assertArrayNotHasKey('password', $profile);
        $this->assertArrayNotHasKey('two_factor_secret', $profile);
        $this->assertArrayNotHasKey('remember_token', $profile);

        // Entries the user wrote about others are listed without the
        // details describing those other people.
        $performed = $response->json('activity.performed_by_user.0');
        $this->assertSame($other->id, $performed['item_id']);
        $this->assertSame('203.0.113.5', $performed['remote_ip']);
        $this->assertArrayNotHasKey('note', $performed);
        $this->assertArrayNotHasKey('log_meta', $performed);
    }

    public function test_export_is_recorded_in_the_users_history()
    {
        $user = $this->personWithData();
        $admin = User::factory()->superuser()->create();

        $this->actingAsForApi($admin)->getJson(route('api.users.personal-data', $user))->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'item_type' => User::class,
            'item_id' => $user->id,
            'action_type' => ActionType::PersonalDataExported->value,
            'created_by' => $admin->id,
        ]);
    }

    public function test_export_of_other_users_requires_superuser()
    {
        $user = $this->personWithData();

        $this->actingAsForApi(User::factory()->admin()->create())
            ->getJson(route('api.users.personal-data', $user))
            ->assertForbidden();
    }

    public function test_requires_permission()
    {
        $user = $this->personWithData();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.personal-data', $user))
            ->assertForbidden();
    }

    public function test_users_can_export_their_own_data()
    {
        $user = $this->personWithData();

        $this->actingAsForApi($user)
            ->getJson(route('api.account.personal-data'))
            ->assertOk()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('profile.username', 'jane.doe');
    }

    public function test_deleted_users_can_be_exported()
    {
        $user = $this->personWithData();
        DB::table('assets')->update(['assigned_to' => null, 'assigned_type' => null]);
        $user->delete();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.users.personal-data', $user->id))
            ->assertOk()
            ->assertJsonPath('user_id', $user->id);
    }

    public function test_superuser_can_download_export_from_the_user_page()
    {
        $user = $this->personWithData();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('users.personal-data', $user))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="personal-data-user-'.$user->id.'-'.now()->format('Y-m-d').'.json"')
            ->assertJsonPath('profile.username', 'jane.doe');
    }
}
