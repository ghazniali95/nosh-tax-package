<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KPRA authenticates per-request with pos_id + a secret "key" carried in the
 * request body (no bearer token). pos_id and mode already exist (added for SRB);
 * this adds the KPRA `api_key`, encrypted at rest by the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('mode'); // KPRA body "key" (encrypted)
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }
};
