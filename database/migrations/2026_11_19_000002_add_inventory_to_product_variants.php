<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Existing variants start at stock 0: the real stock is loaded afterwards with stock movements.
 * `inventory_movements` is the audit trail: `quantity` is signed and `stock_after` is the resulting stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // entrada | salida | ajuste | venta | devolucion
            $table->integer('quantity');
            $table->unsignedInteger('stock_after');
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'low_stock_threshold']);
        });
    }
};
