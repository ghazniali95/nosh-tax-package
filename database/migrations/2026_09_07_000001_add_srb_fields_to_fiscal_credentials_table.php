<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRB (Sindh Revenue Board) needs a POS ID plus, for cloud mode, gateway
 * credentials (posUser/posPass) and a mode flag (cloud vs offline connector).
 * These are additive and nullable, so existing FBR rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table) {
            $table->string('pos_id')->nullable()->after('token');       // SRB registered POS ID
            $table->text('pos_user')->nullable()->after('pos_id');      // SRB cloud username (encrypted)
            $table->text('pos_pass')->nullable()->after('pos_user');    // SRB cloud password (encrypted)
            $table->string('mode')->nullable()->after('pos_pass');      // 'cloud' | 'offline'
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_credentials', function (Blueprint $table) {
            $table->dropColumn(['pos_id', 'pos_user', 'pos_pass', 'mode']);
        });
    }
};
