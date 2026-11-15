<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('scim_existing_groups_only')->default(0);
            $table->boolean('scim_require_group_membership')->default(0);
        });

        // What the SCIM client last said about the account ("active"),
        // kept apart from users.activated, which can additionally depend on
        // group membership.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('scim_active')->nullable()->default(null);
        });

        DB::table('users')
            ->whereNotNull('scim_externalid')
            ->update(['scim_active' => DB::raw('activated')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['scim_existing_groups_only', 'scim_require_group_membership']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('scim_active');
        });
    }
};
