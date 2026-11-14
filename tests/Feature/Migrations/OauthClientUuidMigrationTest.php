<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
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

        app(ClientRepository::class)->createPersonalAccessGrantClient('Existing client', 'users');
        $token = User::factory()->create()->createToken('existing-token');

        // Back to the Passport 12 shape (bigint ids), as on an install
        // that has not run the conversion yet, then convert again.
        $migration->down();
        $legacyClientId = DB::table('oauth_access_tokens')->where('id', $token->token->id)->value('client_id');
        $this->assertSame($legacyClientId, DB::table('oauth_clients')->where('name', 'Existing client')->value('id'));

        $migration->up();

        // The client keeps its ID (as a string) and its tokens stay linked.
        $this->assertSame((string) $legacyClientId, DB::table('oauth_clients')->where('name', 'Existing client')->value('id'));
        $this->assertSame((string) $legacyClientId, DB::table('oauth_access_tokens')->where('id', $token->token->id)->value('client_id'));
    }

    public function test_token_issued_before_the_conversion_still_authenticates()
    {
        $migration = require database_path('migrations/2026_11_10_164924_migrate_oauth_clients_to_uuid_primary_keys.php');
        $user = User::factory()->superuser()->create();

        // Passport 12 shape: integer client IDs, and a token issued for
        // client 7, i.e. a JWT whose "aud" claim is "7".
        $migration->down();
        DB::table('oauth_clients')->insert([
            'id' => 7, 'name' => 'Legacy client', 'secret' => null, 'provider' => 'users', 'redirect' => 'http://localhost',
            'personal_access_client' => 1, 'password_client' => 0, 'revoked' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('oauth_access_tokens')->insert([
            'id' => 'legacy-token-id', 'user_id' => $user->id, 'client_id' => 7, 'name' => 'legacy',
            'scopes' => '[]', 'revoked' => 0, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addYear(),
        ]);
        $jwt = $this->legacyAccessToken(clientId: '7', tokenId: 'legacy-token-id', userId: $user->id);

        $migration->up();

        $this->assertSame('7', DB::table('oauth_clients')->where('name', 'Legacy client')->value('id'));
        $this->withHeader('Authorization', 'Bearer '.$jwt)
            ->getJson('/api/v1/users/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    private function legacyAccessToken(string $clientId, string $tokenId, int $userId): string
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::file(Passport::keyPath('oauth-private.key')),
            InMemory::file(Passport::keyPath('oauth-public.key')),
        );

        return $config->builder()
            ->permittedFor($clientId)
            ->identifiedBy($tokenId)
            ->issuedAt(new DateTimeImmutable('-1 minute'))
            ->canOnlyBeUsedAfter(new DateTimeImmutable('-1 minute'))
            ->expiresAt(new DateTimeImmutable('+1 year'))
            ->relatedTo((string) $userId)
            ->withClaim('scopes', [])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }
}
