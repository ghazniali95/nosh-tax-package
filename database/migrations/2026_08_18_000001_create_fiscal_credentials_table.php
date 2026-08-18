<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('authority')->default('fbr');
            $table->boolean('sandbox')->default(true);
            $table->text('token')->nullable();          // encrypted at rest by the model cast
            $table->string('seller_ntncnic')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_province')->nullable();
            $table->string('seller_address')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'authority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_credentials');
    }
};
