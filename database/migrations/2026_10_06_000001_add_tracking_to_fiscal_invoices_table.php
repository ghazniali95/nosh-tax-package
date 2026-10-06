<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.2 — tie a fiscal invoice to the host app's own record and keep what an
 * operator needs to chase a stuck one.
 *
 *   reference   the host's key for the sale ("bill:1234") — find the fiscal
 *               record from a bill without storing anything on the bill
 *   attempts    how many times the authority was tried
 *   last_error  the most recent rejection or transport failure, verbatim
 *   qr_payload  what the QR must encode; SRB/PRA encode a verification URL,
 *               not the fiscal number, so it cannot be rebuilt from the number
 *
 * All additive and nullable / defaulted, so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_invoices', function (Blueprint $table) {
            $table->string('reference')->nullable()->index()->after('tenant_id');
            $table->unsignedInteger('attempts')->default(0)->after('status');
            $table->text('last_error')->nullable()->after('attempts');
            $table->string('qr_payload', 1024)->nullable()->after('fiscal_number');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_invoices', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn(['reference', 'attempts', 'last_error', 'qr_payload']);
        });
    }
};
