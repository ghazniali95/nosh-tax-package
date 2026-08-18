<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_reference_data', function (Blueprint $table) {
            $table->id();
            $table->string('authority')->default('fbr');
            $table->string('type');            // provinces | units | hsCodes | taxRates ...
            $table->json('data');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['authority', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_reference_data');
    }
};
