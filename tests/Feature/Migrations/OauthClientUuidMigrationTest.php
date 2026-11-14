<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * Passport 13 generates UUID client IDs. The migration converting the
 * oauth tables must therefore also run on installs that connect with the
 * "mariadb" driver, not only "mysql".
 */
class OauthClientUuidMigrationTest extends TestCase
{
    public function test_oauth_client_ids_are_uuid_columns()
    {
        foreach ([
            ['oauth_clients', 'id'],
            ['oauth_access_tokens', 'client_id'],
            ['oauth_auth_codes', 'client_id'],
            ['oauth_personal_access_clients', 'client_id'],
        ] as [$table, $column]) {
            $this->assertContains(
                Schema::getColumnType($table, $column),
                ['char', 'string', 'varchar', 'text'],
                "{$table}.{$column} is not a UUID-capable column on the ".DB::getDriverName().' driver'
            );
        }
    }

    public function test_personal_access_token_can_be_created()
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test Personal Access Client', 'users');

        $token = User::factory()->create()->createToken('privacy-test');

        $this->assertNotEmpty($token->accessToken);
        $this->assertSame(36, strlen((string) DB::table('oauth_access_tokens')->where('id', $token->token->id)->value('client_id')));
    }

    public function test_existing_clients_and_tokens_survive_the_conversion()
    {
        $migration = require database_path('migrations/2026_11_10_164924_migrate_oauth_clients_to_uuid_primary_keys.php');

        $client = app(ClientRepository::class)->createPersonalAccessGrantClient('Existing client', 'users');
        $token = User::factory()->create()->createToken('existing-token');

        // Back to the Passport 12 shape (bigint ids), as on an install
        // that has not run the conversion yet, then convert again.
        $migration->down();
        $legacyClientId = DB::table('oauth_access_tokens')->where('id', $token->token->id)->value('client_id');
        $this->assertSame($legacyClientId, DB::table('oauth_clients')->where('name', 'Existing client')->value('id'));

        $migration->up();

        $newClientId = DB::table('oauth_clients')->where('name', 'Existing client')->value('id');
        $this->assertSame(36, strlen((string) $newClientId));
        $this->assertSame($newClientId, DB::table('oauth_access_tokens')->where('id', $token->token->id)->value('client_id'));
        $this->assertNotSame($client->getKey(), $newClientId);
    }
}
