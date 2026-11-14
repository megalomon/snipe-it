<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logouts received from the OpenID Connect provider via back-channel
     * logout. A row with a sid ends that one provider session; a row
     * without a sid ends every session of the subject that started before
     * logged_out_at. Rows are pruned once older than the session lifetime.
     */
    public function up(): void
    {
        Schema::create('oidc_logouts', function (Blueprint $table) {
            $table->id();
            $table->string('issuer', 191);
            $table->string('subject', 191)->nullable();
            $table->string('sid', 191)->nullable();
            $table->timestamp('logged_out_at');
            $table->timestamps();

            $table->index(['issuer', 'sid']);
            $table->index(['issuer', 'subject']);
            $table->index('logged_out_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oidc_logouts');
    }
};
