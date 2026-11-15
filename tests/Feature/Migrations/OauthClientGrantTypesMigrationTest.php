<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\TestCase;

/**
 * Clients carried over from Passport 12 keep their integer IDs. Without an
 * explicit grant_types column, Passport 13 infers "client_credentials" for
 * every confidential first-party client and then rejects any token whose
 * user ID equals the client ID, e.g. every token of user 1 issued by
 * client 1.
 */
class OauthClientGrantTypesMigrationTest extends TestCase
{
    private function uuidMigration(): object
    {
        return require database_path('migrations/2026_11_10_164924_migrate_oauth_clients_to_uuid_primary_keys.php');
    }

    private function grantTypesMigration(): object
    {
        return require database_path('migrations/2026_11_15_005949_add_grant_types_to_oauth_clients_table.php');
    }

    /**
     * Rebuilds the Passport 12 situation of a live install: a confidential
     * personal access client and a password client with integer IDs.
     */
    private function legacyClients(int $personalClientId, int $passwordClientId): void
    {
        $this->grantTypesMigration()->down();
        $this->uuidMigration()->down();

        DB::table('oauth_clients')->insert([
            ['id' => $personalClientId, 'user_id' => null, 'name' => 'Snipe-IT Personal Access Client', 'secret' => 'secret', 'provider' => null,
                'redirect' => 'http://localhost', 'personal_access_client' => 1, 'password_client' => 0, 'revoked' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $passwordClientId, 'user_id' => null, 'name' => 'Snipe-IT Password Grant Client', 'secret' => 'secret', 'provider' => 'users',
                'redirect' => 'http://localhost', 'personal_access_client' => 0, 'password_client' => 1, 'revoked' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('oauth_personal_access_clients')->insert(['client_id' => $personalClientId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_token_of_user_whose_id_equals_the_client_id_authenticates()
    {
        $user = User::factory()->superuser()->create(['id' => 4242]);
        $this->legacyClients(personalClientId: 4242, passwordClientId: 4243);
        DB::table('oauth_access_tokens')->insert([
            'id' => 'legacy-token-id', 'user_id' => $user->id, 'client_id' => 4242, 'name' => 'legacy',
            'scopes' => '[]', 'revoked' => 0, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addYear(),
        ]);
        $jwt = $this->legacyAccessToken(clientId: '4242', tokenId: 'legacy-token-id', userId: $user->id);

        $this->uuidMigration()->up();
        $this->grantTypesMigration()->up();

        $this->withHeader('Authorization', 'Bearer '.$jwt)
            ->getJson('/api/v1/users/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_existing_clients_get_their_grant_types()
    {
        $this->legacyClients(personalClientId: 4242, passwordClientId: 4243);

        $this->uuidMigration()->up();
        $this->grantTypesMigration()->up();

        $this->assertTrue(Schema::hasColumn('oauth_clients', 'grant_types'));
        $personal = json_decode(DB::table('oauth_clients')->where('id', '4242')->value('grant_types'), true);
        $password = json_decode(DB::table('oauth_clients')->where('id', '4243')->value('grant_types'), true);

        $this->assertContains('personal_access', $personal);
        $this->assertNotContains('client_credentials', $personal);
        $this->assertContains('password', $password);
        $this->assertNotContains('client_credentials', $password);
    }

    public function test_new_personal_access_tokens_still_work()
    {
        $this->legacyClients(personalClientId: 4242, passwordClientId: 4243);
        $this->uuidMigration()->up();
        $this->grantTypesMigration()->up();
        $user = User::factory()->superuser()->create();

        $token = $user->createToken('after-upgrade')->accessToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
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
