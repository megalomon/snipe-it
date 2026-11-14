<?php

namespace Tests\Feature\Privacy;

use App\Actions\Users\AnonymizeUserAction;
use App\Enums\ActionType;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Consumable;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnonymizeUserTest extends TestCase
{
    private function personWithData(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'jane.doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+49 261 123456',
            'employee_num' => 'EMP-4711',
            'notes' => 'Prefers the 14 inch model',
            'avatar' => 'jane.png',
            'two_factor_secret' => 'SECRET',
            'oidc_issuer' => 'https://id.example.com/realms/opendesk',
            'oidc_subject' => 'f3c1a2b4',
            'scim_externalid' => 'nubus-123',
        ]);
        $user->groups()->attach(Group::factory()->create()->id);

        return $user;
    }

    private function logRow(array $attributes): int
    {
        return DB::table('action_logs')->insertGetId(array_merge([
            'action_type' => ActionType::NoteAdded->value,
            'remote_ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_profile_is_overwritten_and_account_deleted()
    {
        $user = $this->personWithData();

        AnonymizeUserAction::run($user);

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertSame(trans('admin/users/general.anonymized_first_name'), $row->first_name);
        $this->assertSame('#'.$user->id, $row->last_name);
        $this->assertSame('anonymized-'.$user->id, $row->username);
        $this->assertNull($row->email);
        $this->assertNull($row->phone);
        $this->assertNull($row->employee_num);
        $this->assertNull($row->notes);
        $this->assertNull($row->avatar);
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->oidc_subject);
        $this->assertNull($row->scim_externalid);
        $this->assertSame(0, (int) $row->activated);
        $this->assertSame($user->noPassword(), $row->password);
        $this->assertNotNull($row->anonymized_at);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(0, DB::table('users_groups')->where('user_id', $user->id)->count());
    }

    public function test_no_log_entry_keeps_the_old_profile_values()
    {
        $user = $this->personWithData();

        AnonymizeUserAction::run($user);

        $logs = DB::table('action_logs')->where('item_type', User::class)->where('item_id', $user->id)->get();
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('jane', strtolower((string) $log->log_meta));
            $this->assertStringNotContainsString('jane', strtolower((string) $log->note));
        }

        $this->assertDatabaseHas('action_logs', [
            'item_type' => User::class,
            'item_id' => $user->id,
            'action_type' => ActionType::Anonymized->value,
        ]);
    }

    public function test_activity_log_is_scrubbed_but_kept()
    {
        $user = $this->personWithData();
        $other = User::factory()->create();
        $asset = Asset::factory()->create();

        $profileChange = $this->logRow([
            'action_type' => ActionType::Update->value,
            'item_type' => User::class,
            'item_id' => $user->id,
            'log_meta' => json_encode(['phone' => ['old' => '+49 261 999', 'new' => '+49 261 123456']]),
        ]);
        $checkout = $this->logRow([
            'action_type' => ActionType::Checkout->value,
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'target_type' => User::class,
            'target_id' => $user->id,
            'note' => 'Handed to Jane Doe (jane.doe@example.com), see EMP-4711. Jane is great.',
            'log_meta' => json_encode(['location_id' => ['old' => 1, 'new' => 2]]),
        ]);
        $performed = $this->logRow([
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'created_by' => $user->id,
            'note' => 'Checked by jane.doe',
        ]);
        $unrelated = $this->logRow([
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'created_by' => $other->id,
            'note' => 'Jane Doe mentioned in an unrelated entry',
        ]);

        AnonymizeUserAction::run($user);

        $placeholder = trans('admin/users/general.anonymized_placeholder');

        $row = DB::table('action_logs')->find($profileChange);
        $this->assertNull($row->log_meta);
        $this->assertNull($row->remote_ip);
        $this->assertNull($row->user_agent);

        $row = DB::table('action_logs')->find($checkout);
        $this->assertSame("Handed to {$placeholder} ({$placeholder}), see {$placeholder}. Jane is great.", $row->note);
        $this->assertSame(['location_id' => ['old' => 1, 'new' => 2]], json_decode($row->log_meta, true));
        $this->assertSame($user->id, (int) $row->target_id);
        $this->assertNull($row->remote_ip);

        $row = DB::table('action_logs')->find($performed);
        $this->assertSame("Checked by {$placeholder}", $row->note);
        $this->assertNull($row->remote_ip);

        // Entries neither about nor by the user are left alone.
        $row = DB::table('action_logs')->find($unrelated);
        $this->assertSame('Jane Doe mentioned in an unrelated entry', $row->note);
        $this->assertSame('203.0.113.5', $row->remote_ip);
    }

    public function test_login_traces_tokens_and_private_data_are_removed()
    {
        $user = $this->personWithData();
        $other = User::factory()->create(['username' => 'someone.else']);

        DB::table('login_attempts')->insert([
            ['username' => 'jane.doe', 'remote_ip' => '203.0.113.5', 'successful' => 1, 'created_at' => now()],
            ['username' => 'jane.doe@example.com', 'remote_ip' => '203.0.113.5', 'successful' => 0, 'created_at' => now()],
            ['username' => 'someone.else', 'remote_ip' => '203.0.113.6', 'successful' => 1, 'created_at' => now()],
        ]);
        DB::table('password_resets')->insert(['email' => 'jane.doe@example.com', 'token' => 'x', 'created_at' => now()]);
        DB::table('oauth_access_tokens')->insert(['id' => 'token-1', 'user_id' => $user->id, 'client_id' => 1, 'revoked' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'refresh-1', 'access_token_id' => 'token-1', 'revoked' => 0]);
        DB::table('saved_searches')->insert(['created_by' => $user->id, 'table_name' => 'assets', 'name' => 'Mine', 'query_string' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('report_templates')->insert([
            ['created_by' => $user->id, 'name' => 'Private', 'options' => '{}', 'is_shared' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['created_by' => $user->id, 'name' => 'Shared', 'options' => '{}', 'is_shared' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('oidc_logouts')->insert(['issuer' => 'https://id.example.com/realms/opendesk', 'subject' => 'f3c1a2b4', 'logged_out_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        AnonymizeUserAction::run($user);

        $this->assertSame(['someone.else'], DB::table('login_attempts')->pluck('username')->all());
        $this->assertSame(0, DB::table('password_resets')->count());
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('access_token_id', 'token-1')->count());
        $this->assertSame(0, DB::table('saved_searches')->where('created_by', $user->id)->count());
        $this->assertSame(['Shared'], DB::table('report_templates')->where('created_by', $user->id)->pluck('name')->all());
        $this->assertSame(0, DB::table('oidc_logouts')->count());
        $this->assertNotNull($other->fresh());
    }

    public function test_avatar_and_uploaded_files_are_deleted()
    {
        Storage::fake('public');
        Storage::fake(config('filesystems.default'));

        $user = $this->personWithData();
        Storage::disk('public')->put('avatars/jane.png', 'image');
        Storage::put('private_uploads/users/contract-jane.pdf', 'pdf');
        $upload = $this->logRow([
            'action_type' => ActionType::Uploaded->value,
            'item_type' => User::class,
            'item_id' => $user->id,
            'filename' => 'contract-jane.pdf',
        ]);

        AnonymizeUserAction::run($user);

        Storage::disk('public')->assertMissing('avatars/jane.png');
        Storage::assertMissing('private_uploads/users/contract-jane.pdf');
        $this->assertNull(DB::table('action_logs')->find($upload));
    }

    public function test_signed_acceptances_are_kept()
    {
        $user = $this->personWithData();
        $acceptance = CheckoutAcceptance::factory()->create([
            'assigned_to_id' => $user->id,
            'signature_filename' => 'signature.png',
            'accepted_at' => now(),
        ]);
        // The device has been returned since; only the signed record remains.
        DB::table('assets')->where('assigned_to', $user->id)->update(['assigned_to' => null, 'assigned_type' => null]);
        $accepted = $this->logRow([
            'action_type' => ActionType::Accepted->value,
            'item_type' => Asset::class,
            'item_id' => Asset::factory()->create()->id,
            'target_type' => User::class,
            'target_id' => $user->id,
            'accept_signature' => 'signature.png',
            'filename' => 'eula.pdf',
        ]);

        AnonymizeUserAction::run($user);

        $this->assertSame('signature.png', DB::table('checkout_acceptances')->where('id', $acceptance->id)->value('signature_filename'));
        $row = DB::table('action_logs')->find($accepted);
        $this->assertSame('signature.png', $row->accept_signature);
        $this->assertSame('eula.pdf', $row->filename);
    }

    public function test_checked_out_items_stay_assigned_to_the_anonymized_account()
    {
        $user = $this->personWithData();
        $asset = Asset::factory()->assignedToUser($user)->create();
        $consumable = Consumable::factory()->checkedOutToUser($user)->create();

        AnonymizeUserAction::run($user);

        $this->assertNotNull($user->fresh()->anonymized_at);
        $this->assertSame($user->id, (int) $asset->fresh()->assigned_to);
        $this->assertSame(User::class, $asset->fresh()->assigned_type);
        $this->assertSame(1, DB::table('consumables_users')->where('consumable_id', $consumable->id)->where('assigned_to', $user->id)->count());
        // Still resolvable (and checkable-in) although the account is deleted.
        $this->assertSame('anonymized-'.$user->id, $asset->fresh()->assignedTo->username);
    }

    public function test_managed_users_keep_pointing_at_the_anonymized_account()
    {
        $user = $this->personWithData();
        $report = User::factory()->create(['manager_id' => $user->id]);

        AnonymizeUserAction::run($user);

        $this->assertSame($user->id, $report->fresh()->manager_id);
        $this->assertNotNull($user->fresh()->anonymized_at);
    }

    public function test_superuser_can_anonymize_from_the_user_page()
    {
        $admin = User::factory()->superuser()->create();
        $user = $this->personWithData();

        $this->actingAs($admin)
            ->get(route('users.show', $user))
            ->assertOk()
            ->assertSee(route('users.anonymize', $user), false);

        $this->actingAs($admin)
            ->post(route('users.anonymize', $user))
            ->assertRedirect(route('users.show', $user))
            ->assertSessionHas('success');

        $this->assertNotNull($user->fresh()->anonymized_at);
        $this->assertDatabaseHas('action_logs', [
            'item_id' => $user->id,
            'item_type' => User::class,
            'action_type' => ActionType::Anonymized->value,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('users.show', $user))
            ->assertOk()
            ->assertSee(trans('admin/users/general.anonymized_first_name'));
    }

    public function test_anonymizing_requires_superuser()
    {
        $user = $this->personWithData();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.anonymize', $user))
            ->assertForbidden();

        $this->assertNull($user->fresh()->anonymized_at);
    }

    public function test_api_anonymizing_requires_superuser()
    {
        $user = $this->personWithData();

        $this->actingAsForApi(User::factory()->admin()->create())
            ->postJson(route('api.users.anonymize', $user))
            ->assertForbidden();

        $this->assertNull($user->fresh()->anonymized_at);
    }

    public function test_superuser_cannot_anonymize_themselves()
    {
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->post(route('users.anonymize', $admin))
            ->assertSessionHas('error', trans('admin/users/message.anonymize.not_self'));

        $this->assertNull($admin->fresh()->anonymized_at);
    }

    public function test_api_anonymizes_user()
    {
        $user = $this->personWithData();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.users.anonymize', $user))
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertNotNull($user->fresh()->anonymized_at);
    }

    public function test_api_anonymizes_user_with_checked_out_items()
    {
        $user = $this->personWithData();
        $asset = Asset::factory()->assignedToUser($user)->create();

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->postJson(route('api.users.anonymize', $user))
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertNotNull($user->fresh()->anonymized_at);
        $this->assertSame($user->id, (int) $asset->fresh()->assigned_to);
    }
}
