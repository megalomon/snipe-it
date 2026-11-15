<?php

namespace Tests\Feature\Scim;

use App\Models\Group;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The two SCIM settings used with openDesk / Nubus: only groups that
 * already exist in Snipe-IT are accepted, and SCIM-managed accounts are
 * only active while they belong to at least one Snipe-IT group.
 */
class ScimGroupRequirementTest extends TestCase
{
    private const CORE_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const CORE_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    protected function setUp(): void
    {
        parent::setUp();

        Passport::actingAs(User::factory()->superuser()->create());
    }

    private function scim(string $method, string $uri, ?array $body = null): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/scim+json',
            'HTTP_ACCEPT' => 'application/scim+json',
            'HTTP_USER_AGENT' => 'python-httpx/0.28.1',
        ], $body === null ? null : json_encode($body));
    }

    private function userPayload(string $username, bool $active = true): array
    {
        return [
            'schemas' => [self::CORE_USER],
            'externalId' => 'ext-'.$username,
            'userName' => $username,
            'active' => $active,
            'name' => ['givenName' => ucfirst($username), 'familyName' => 'Test'],
        ];
    }

    /**
     * @param  list<User>  $members
     */
    private function groupPayload(string $name, array $members = [], string $externalId = 'ext-group'): array
    {
        return [
            'schemas' => [self::CORE_GROUP],
            'externalId' => $externalId,
            'displayName' => $name,
            'members' => array_map(fn (User $user) => ['value' => (string) $user->id], $members),
        ];
    }

    private function provisionUser(string $username, bool $active = true): User
    {
        $id = $this->scim('POST', '/scim/v2/Users', $this->userPayload($username, $active))->assertCreated()->json('id');

        return User::findOrFail($id);
    }

    public function test_unknown_group_is_refused_when_only_existing_groups_are_accepted()
    {
        $this->settings->set(['scim_existing_groups_only' => 1]);

        $this->scim('POST', '/scim/v2/Groups', $this->groupPayload('not-in-snipe-it'))
            ->assertStatus(403);

        $this->assertDatabaseMissing('permission_groups', ['name' => 'not-in-snipe-it']);
    }

    public function test_existing_group_is_still_adopted_when_only_existing_groups_are_accepted()
    {
        $this->settings->set(['scim_existing_groups_only' => 1]);
        $group = Group::factory()->create(['name' => 'snipeit-helpdesk', 'permissions' => json_encode(['assets.view' => '1'])]);

        $this->scim('POST', '/scim/v2/Groups', $this->groupPayload('snipeit-helpdesk'))
            ->assertCreated()
            ->assertJsonPath('id', (string) $group->id);

        $this->assertSame('ext-group', $group->fresh()->scim_externalid);
        $this->assertSame(['assets.view' => '1'], json_decode($group->fresh()->permissions, true));
    }

    public function test_unknown_group_is_created_when_the_setting_is_off()
    {
        $this->scim('POST', '/scim/v2/Groups', $this->groupPayload('new-from-idp'))->assertCreated();

        $this->assertDatabaseHas('permission_groups', ['name' => 'new-from-idp']);
    }

    public function test_account_without_group_is_created_deactivated()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);

        $user = $this->provisionUser('jdoe');

        $this->assertSame(0, (int) $user->activated);
        $this->assertTrue($user->scim_active);
    }

    public function test_account_is_activated_when_added_to_a_group_and_deactivated_when_removed()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);
        Group::factory()->create(['name' => 'snipeit-helpdesk']);
        $user = $this->provisionUser('jdoe');

        $groupId = $this->scim('POST', '/scim/v2/Groups', $this->groupPayload('snipeit-helpdesk', [$user]))
            ->assertCreated()
            ->json('id');
        $this->assertSame(1, (int) $user->fresh()->activated);

        $this->scim('PUT', '/scim/v2/Groups/'.$groupId, $this->groupPayload('snipeit-helpdesk'))->assertOk();
        $this->assertSame(0, (int) $user->fresh()->activated);
    }

    public function test_account_disabled_by_the_identity_provider_stays_deactivated_despite_groups()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);
        $user = $this->provisionUser('jdoe', active: false);
        $user->groups()->attach(Group::factory()->create()->id);

        $this->scim('PUT', '/scim/v2/Users/'.$user->id, $this->userPayload('jdoe', active: false))->assertOk();

        $this->assertSame(0, (int) $user->fresh()->activated);
    }

    public function test_reporting_an_account_active_does_not_activate_it_without_a_group()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);
        $user = $this->provisionUser('jdoe');

        $this->scim('PUT', '/scim/v2/Users/'.$user->id, $this->userPayload('jdoe'))->assertOk();

        $this->assertSame(0, (int) $user->fresh()->activated);
    }

    public function test_local_accounts_are_not_affected()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);
        $local = User::factory()->create(['activated' => 1]);

        $this->provisionUser('jdoe');

        $this->assertSame(1, (int) $local->fresh()->activated);
    }

    public function test_accounts_stay_active_without_groups_when_the_setting_is_off()
    {
        $user = $this->provisionUser('jdoe');

        $this->assertSame(1, (int) $user->activated);
    }

    public function test_deactivation_is_written_to_the_users_history()
    {
        $this->settings->set(['scim_require_group_membership' => 1]);

        $user = $this->provisionUser('jdoe');

        $this->assertDatabaseHas('action_logs', [
            'item_type' => User::class,
            'item_id' => $user->id,
            'action_type' => 'update',
        ]);
    }
}
