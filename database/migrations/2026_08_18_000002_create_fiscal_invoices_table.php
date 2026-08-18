<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();
            $table->string('authority')->nullable();
            $table->string('idempotency_key')->unique();   // never report the same sale twice
            $table->string('status')->default('pending')->index();
            $table->string('fiscal_number')->nullable()->index();
            $table->json('payload');                        // the canonical invoice
            $table->json('response')->nullable();           // the authority's raw + parsed result
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_invoices');
    }
};
