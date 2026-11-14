<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('privacy_truncate_ip_addresses')->default(0);
            $table->unsignedInteger('privacy_ip_retention_days')->nullable()->default(null);
            $table->unsignedInteger('privacy_login_attempts_retention_days')->nullable()->default(null);
            $table->unsignedInteger('privacy_anonymize_deleted_users_days')->nullable()->default(null);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'privacy_truncate_ip_addresses',
                'privacy_ip_retention_days',
                'privacy_login_attempts_retention_days',
                'privacy_anonymize_deleted_users_days',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
