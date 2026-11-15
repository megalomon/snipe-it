<?php

namespace Tests\Feature\Scim;

use App\Models\Asset;
use App\Models\Group;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Replays the requests the Univention Nubus SCIM client (univention/scim-services,
 * scim-client) sends, so openDesk / Nubus can provision users and groups into
 * Snipe-IT. Payload shapes follow its UDM-to-SCIM mapper (udm2scim.py):
 *
 *  - discovery of /ServiceProviderConfig, /ResourceTypes and /Schemas
 *  - lookup by `externalId eq "<univentionObjectIdentifier>"`
 *  - POST to create, full PUT (no id/meta) to update, DELETE to remove
 *  - application/scim+json request bodies
 */
class NubusScimClientCompatibilityTest extends TestCase
{
    private const CORE_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    private const ENTERPRISE_USER = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

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

    /**
     * A user as Nubus' UDM-to-SCIM mapper produces it.
     */
    private function nubusUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'schemas' => [self::CORE_USER, self::ENTERPRISE_USER],
            'externalId' => 'a1b2c3d4-0000-4000-8000-000000000001',
            'userName' => 'jdoe',
            'active' => true,
            'displayName' => 'Jane Doe',
            'title' => 'Administratorin',
            'preferredLanguage' => 'de-DE',
            'name' => ['givenName' => 'Jane', 'familyName' => 'Doe'],
            'emails' => [
                ['value' => 'jane.doe@example.com', 'type' => 'mailbox', 'primary' => false],
                ['value' => 'jd@example.com', 'type' => 'alias', 'primary' => false],
            ],
            'phoneNumbers' => [
                ['value' => '+49 30 1111', 'type' => 'work'],
                ['value' => '+49 170 2222', 'type' => 'mobile'],
                ['value' => '+49 30 3333', 'type' => 'home'],
                ['value' => '+49 30 4444', 'type' => 'pager'],
            ],
            'addresses' => [
                [
                    'streetAddress' => 'Hauptstraße 1',
                    'locality' => 'Berlin',
                    'postalCode' => '10115',
                    'region' => 'Berlin',
                    'country' => 'DE',
                    'type' => 'work',
                ],
                [
                    'streetAddress' => 'Zuhause 2',
                    'locality' => 'Potsdam',
                    'postalCode' => '14467',
                    'type' => 'home',
                ],
            ],
            'roles' => [
                ['value' => 'umc:udm:admin', 'type' => 'guardian-direct'],
                ['value' => 'umc:udm:users-reader', 'type' => 'guardian-indirect'],
            ],
            self::ENTERPRISE_USER => ['employeeNumber' => '4711'],
        ], $overrides);
    }

    private function nubusGroup(array $members = [], array $overrides = []): array
    {
        return array_replace([
            'schemas' => [self::CORE_GROUP],
            'externalId' => 'a1b2c3d4-0000-4000-8000-0000000000a1',
            'displayName' => 'snipeit-helpdesk',
            'members' => array_map(fn (User $user) => [
                'value' => (string) $user->id,
                'display' => $user->display_name ?? $user->username,
                '$ref' => url('/scim/v2/Users/'.$user->id),
                'type' => 'User',
            ], $members),
        ], $overrides);
    }

    public function test_discovery_offers_what_the_nubus_client_requires()
    {
        $config = $this->scim('GET', '/scim/v2/ServiceProviderConfig')->assertOk()->json();
        $this->assertTrue($config['filter']['supported']);
        $this->assertContains('oauthbearertoken', array_column($config['authenticationSchemes'], 'type'));

        $resourceTypes = $this->scim('GET', '/scim/v2/ResourceTypes')->assertOk()->json('Resources');
        $this->assertEqualsCanonicalizing(['User', 'Group'], array_column($resourceTypes, 'name'));

        $schemas = $this->scim('GET', '/scim/v2/Schemas')->assertOk()->json('Resources');
        $this->assertContains(self::CORE_USER, array_column($schemas, 'id'));
        $this->assertContains(self::CORE_GROUP, array_column($schemas, 'id'));
    }

    public function test_schemas_declare_the_types_the_client_validates_against()
    {
        $schemas = collect($this->scim('GET', '/scim/v2/Schemas')->assertOk()->json('Resources'))->keyBy('id');
        $attribute = fn (string $schema, string $name) => collect($schemas[$schema]['attributes'])->firstWhere('name', $name);

        $this->assertSame('boolean', $attribute(self::CORE_USER, 'active')['type']);
        $this->assertSame('complex', $attribute(self::CORE_USER, 'groups')['type']);
        $this->assertSame('complex', $attribute(self::CORE_USER, 'roles')['type']);
        $this->assertSame('complex', $attribute(self::CORE_GROUP, 'members')['type']);

        // Every attribute with sub-attributes must be complex (RFC 7643).
        $walk = function (array $attributes) use (&$walk) {
            foreach ($attributes as $attr) {
                if (! empty($attr['subAttributes'])) {
                    $this->assertSame('complex', $attr['type'], $attr['name'].' has sub-attributes but is not complex');
                    $walk($attr['subAttributes']);
                }
            }
        };
        foreach ($schemas as $schema) {
            $walk($schema['attributes']);
        }
    }

    public function test_user_schema_extensions_have_distinct_names()
    {
        $names = collect($this->scim('GET', '/scim/v2/Schemas')->assertOk()->json('Resources'))->pluck('name', 'id');

        $this->assertSame('EnterpriseUser', $names[self::ENTERPRISE_USER]);
        $this->assertSame('GrokabilityUser', $names['urn:ietf:params:scim:schemas:extension:grokability:2.0:User']);
        $this->assertSame($names->count(), $names->unique()->count());
    }

    public function test_full_sync_is_not_rate_limited()
    {
        config(['app.api_throttle_per_minute' => 3]);

        for ($i = 0; $i < 6; $i++) {
            $this->scim('GET', '/scim/v2/Users?filter='.rawurlencode('externalId eq "unknown-'.$i.'"'))->assertOk();
        }
    }

    public function test_client_user_agent_passes_the_default_api_user_agent_block_list()
    {
        $this->settings->set([
            'block_api_user_agents' => 1,
            'block_blank_api_user_agents' => 1,
            'blocked_api_user_agents' => implode("\r\n", \App\Models\Setting::DEFAULT_BLOCKED_API_USER_AGENTS),
        ]);

        foreach (['python-httpx/0.28.1', 'python-httpx2/2.15.1'] as $userAgent) {
            $this->call('GET', '/scim/v2/Users', [], [], [], [
                'HTTP_ACCEPT' => 'application/scim+json',
                'HTTP_USER_AGENT' => $userAgent,
            ])->assertOk();
        }
    }

    public function test_user_is_created_from_a_full_nubus_payload()
    {
        $response = $this->scim('POST', '/scim/v2/Users', $this->nubusUser());

        $response->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();
        $this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $user->scim_externalid);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Doe', $user->last_name);
        $this->assertSame('Jane Doe', $user->display_name);
        $this->assertSame('jane.doe@example.com', $user->email);
        $this->assertSame('+49 30 1111', $user->phone);
        $this->assertSame('+49 170 2222', $user->mobile);
        $this->assertSame('Hauptstraße 1', $user->address);
        $this->assertSame('Berlin', $user->city);
        $this->assertSame('10115', $user->zip);
        $this->assertSame('Administratorin', $user->jobtitle);
        $this->assertSame('4711', $user->employee_num);
        $this->assertSame(1, (int) $user->activated);
    }

    public function test_user_is_found_by_external_id_filter()
    {
        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();

        $found = $this->scim('GET', '/scim/v2/Users?filter='.rawurlencode('externalId eq "a1b2c3d4-0000-4000-8000-000000000001"'))
            ->assertOk();
        $this->assertSame(1, $found->json('totalResults'));
        $this->assertSame((string) $user->id, $found->json('Resources.0.id'));

        $this->scim('GET', '/scim/v2/Users?filter='.rawurlencode('externalId eq "does-not-exist"'))
            ->assertOk()
            ->assertJsonPath('totalResults', 0);
    }

    public function test_user_is_updated_with_a_full_put()
    {
        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Users/'.$user->id, $this->nubusUser([
            'name' => ['familyName' => 'Doe-Smith'],
            'emails' => [['value' => 'jane.doe-smith@example.com', 'type' => 'mailbox', 'primary' => false]],
            'title' => 'Teamleitung',
        ]))->assertOk();

        $user->refresh();
        $this->assertSame('Doe-Smith', $user->last_name);
        $this->assertSame('jane.doe-smith@example.com', $user->email);
        $this->assertSame('Teamleitung', $user->jobtitle);
    }

    public function test_user_disabled_in_nubus_is_deactivated()
    {
        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Users/'.$user->id, $this->nubusUser(['active' => false]))->assertOk();

        $this->assertSame(0, (int) $user->fresh()->activated);
    }

    public function test_user_without_roles_or_phones_is_created()
    {
        $payload = $this->nubusUser();
        unset($payload['roles'], $payload['phoneNumbers'], $payload['addresses']);

        $this->scim('POST', '/scim/v2/Users', $payload)->assertCreated();
    }

    public function test_user_is_deleted()
    {
        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();

        $this->scim('DELETE', '/scim/v2/Users/'.$user->id)->assertNoContent();

        $this->assertSoftDeleted($user);
    }

    public function test_user_with_checked_out_assets_is_deactivated_instead_of_deleted()
    {
        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();
        $user = User::where('username', 'jdoe')->firstOrFail();
        Asset::factory()->assignedToUser($user)->create();

        $this->scim('DELETE', '/scim/v2/Users/'.$user->id)->assertNoContent();

        $user = User::withTrashed()->find($user->id);
        $this->assertFalse($user->trashed());
        $this->assertSame(0, (int) $user->activated);
    }

    public function test_group_is_created_with_members_and_found_by_external_id()
    {
        $members = User::factory()->count(2)->create();

        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup($members->all()))->assertCreated();

        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();
        $this->assertEqualsCanonicalizing($members->pluck('id')->all(), $group->users()->pluck('users.id')->all());

        $this->scim('GET', '/scim/v2/Groups?filter='.rawurlencode('externalId eq "a1b2c3d4-0000-4000-8000-0000000000a1"'))
            ->assertOk()
            ->assertJsonPath('totalResults', 1)
            ->assertJsonPath('Resources.0.id', (string) $group->id);
    }

    public function test_group_members_are_replaced_with_a_full_put()
    {
        [$stays, $leaves, $joins] = User::factory()->count(3)->create()->all();
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup([$stays, $leaves]))->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Groups/'.$group->id, $this->nubusGroup([$stays, $joins]))->assertOk();

        $this->assertEqualsCanonicalizing([$stays->id, $joins->id], $group->users()->pluck('users.id')->all());
    }

    public function test_put_with_an_empty_member_list_empties_the_group()
    {
        $members = User::factory()->count(2)->create();
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup($members->all()))->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Groups/'.$group->id, $this->nubusGroup([]))->assertOk();

        $this->assertSame(0, $group->users()->count());
    }

    public function test_member_removal_is_written_to_the_users_history()
    {
        [$stays, $leaves] = User::factory()->count(2)->create()->all();
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup([$stays, $leaves]))->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Groups/'.$group->id, $this->nubusGroup([$stays]))->assertOk();

        $log = $leaves->fresh()->history()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame([], json_decode($log->log_meta, true)['groups']['new']);
    }

    public function test_patch_replace_of_members_still_only_adds_for_entra_id()
    {
        // Entra ID sends op=replace with just the user being provisioned;
        // that must not drop the group's other members.
        [$existing, $added] = User::factory()->count(2)->create()->all();
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup([$existing]))->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('PATCH', '/scim/v2/Groups/'.$group->id, [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'members', 'value' => [['value' => (string) $added->id]]]],
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$existing->id, $added->id], $group->users()->pluck('users.id')->all());
    }

    public function test_existing_local_group_is_adopted_with_its_permissions()
    {
        $local = Group::factory()->create(['name' => 'snipeit-helpdesk', 'permissions' => json_encode(['assets.view' => '1'])]);
        [$fromNubus, $localOnly] = User::factory()->count(2)->create()->all();
        $local->users()->attach($localOnly->id);

        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup([$fromNubus]))->assertCreated();

        $this->assertSame(1, Group::where('name', 'snipeit-helpdesk')->count());
        $local->refresh();
        $this->assertSame('a1b2c3d4-0000-4000-8000-0000000000a1', $local->scim_externalid);
        $this->assertSame(['assets.view' => '1'], json_decode($local->permissions, true));
        $this->assertSame([$fromNubus->id], $local->users()->pluck('users.id')->all());
    }

    public function test_group_linked_to_another_identity_is_not_taken_over()
    {
        $linked = Group::factory()->create(['name' => 'snipeit-helpdesk', 'scim_externalid' => 'someone-else']);

        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup())->assertStatus(400);

        $this->assertSame('someone-else', $linked->fresh()->scim_externalid);
    }

    public function test_existing_local_user_is_adopted_by_username()
    {
        $local = User::factory()->create(['username' => 'jdoe']);

        $this->scim('POST', '/scim/v2/Users', $this->nubusUser())->assertCreated();

        $this->assertSame(1, User::where('username', 'jdoe')->count());
        $this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $local->fresh()->scim_externalid);
    }

    public function test_group_rename_keeps_the_group()
    {
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup())->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('PUT', '/scim/v2/Groups/'.$group->id, $this->nubusGroup([], ['displayName' => 'snipeit-support']))->assertOk();

        $this->assertSame('snipeit-support', $group->fresh()->name);
    }

    public function test_group_is_deleted()
    {
        $this->scim('POST', '/scim/v2/Groups', $this->nubusGroup())->assertCreated();
        $group = Group::where('name', 'snipeit-helpdesk')->firstOrFail();

        $this->scim('DELETE', '/scim/v2/Groups/'.$group->id)->assertNoContent();

        $this->assertNull(Group::find($group->id));
    }
}
