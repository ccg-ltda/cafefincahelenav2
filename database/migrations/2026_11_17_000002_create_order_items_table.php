<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 100);
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cop');
            $table->unsignedBigInteger('subtotal_cop');
            $table->timestamps();
            $table->index('sku');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_check CHECK (quantity > 0 AND subtotal_cop = quantity * unit_price_cop)');
        }
    }

    public function down(): void { Schema::dropIfExists('order_items'); }
};
