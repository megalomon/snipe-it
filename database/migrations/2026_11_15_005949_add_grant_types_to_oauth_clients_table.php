<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passport 13 reads a client's allowed grants from oauth_clients.grant_types.
 * Without that column it infers them, and every confidential first-party
 * client (such as Snipe-IT's personal access and password grant clients
 * created under Passport 12) is assumed to allow "client_credentials".
 *
 * Passport then rejects any token whose user ID equals the client ID as a
 * client-credentials token. Clients carried over from Passport 12 keep
 * their integer IDs (so tokens issued before the upgrade stay valid), so
 * every token of user 1 issued by client 1 was refused.
 *
 * This adds the column and stores each existing client's grants
 * explicitly. Personal access and password clients no longer claim
 * "client_credentials"; clients that are neither keep what Passport
 * inferred for them. Clients created afterwards get the column filled in
 * by Passport itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('oauth_clients', 'grant_types')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->text('grant_types')->nullable();
            });
        }

        foreach (DB::table('oauth_clients')->whereNull('grant_types')->get() as $client) {
            DB::table('oauth_clients')
                ->where('id', $client->id)
                ->update(['grant_types' => json_encode($this->grantTypesFor($client))]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('oauth_clients', 'grant_types')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->dropColumn('grant_types');
            });
        }
    }

    /**
     * The grants Passport 13 would infer for the client, minus
     * "client_credentials" for personal access and password clients.
     *
     * @return list<string>
     */
    private function grantTypesFor(object $client): array
    {
        $confidential = ! empty($client->secret);
        $firstParty = empty($client->user_id ?? null);
        $hasRedirect = ! empty($client->redirect ?? null);
        $personalAccess = (bool) ($client->personal_access_client ?? false);
        $password = (bool) ($client->password_client ?? false);

        return array_keys(array_filter([
            'authorization_code' => $hasRedirect,
            'client_credentials' => $confidential && $firstParty && ! $personalAccess && ! $password,
            'implicit' => $hasRedirect,
            'password' => $password,
            'personal_access' => $personalAccess && $confidential,
            'refresh_token' => true,
            'urn:ietf:params:oauth:grant-type:device_code' => true,
        ]));
    }
};
