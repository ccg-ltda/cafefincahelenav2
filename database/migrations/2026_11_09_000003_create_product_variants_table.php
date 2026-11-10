<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('name');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->string('grind_type', 100)->nullable();
            // Whole Colombian pesos; an integer avoids floating-point rounding.
            $table->unsignedBigInteger('price_cop');
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
