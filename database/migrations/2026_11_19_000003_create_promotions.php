<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. `starts_at` / `ends_at` are stored in UTC (the admin works in America/Bogota).
 * `order_items.promotion_id` is RESTRICT: a promotion that was used in an order cannot be deleted
 * (it can be deactivated), so the order history never loses what was sold with a discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('discount_type', 20); // percentage | fixed_amount
            $table->unsignedInteger('discount_value'); // % or COP
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_variant', function (Blueprint $table) {
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'product_variant_id']);
            $table->index('product_variant_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('original_unit_price_cop')->nullable()->after('unit_price_cop');
            $table->foreignId('promotion_id')->nullable()->after('original_unit_price_cop')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('original_unit_price_cop');
        });
        Schema::dropIfExists('promotion_variant');
        Schema::dropIfExists('promotions');
    }
};
