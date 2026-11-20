<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Coupons are general discounts on the order (not tied to a product). Uses are counted from
 * `coupon_redemptions` (one row per order, `released_at` frees the use when an order is cancelled).
 * `orders.coupon_id` and `coupon_redemptions.coupon_id` are RESTRICT: a coupon that was used cannot be deleted
 * (it can be deactivated). `starts_at` / `ends_at` are UTC (the admin works in America/Bogota).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // stored in UPPERCASE
            $table->string('description', 255)->nullable();
            $table->string('discount_type', 20); // percentage | fixed_amount
            $table->unsignedInteger('discount_value'); // % or COP
            $table->unsignedInteger('max_discount_cop')->nullable(); // cap for percentages
            $table->unsignedInteger('min_subtotal_cop')->default(0);
            $table->unsignedInteger('max_uses')->nullable(); // null = unlimited
            $table->unsignedInteger('max_uses_per_email')->nullable();
            $table->boolean('exclude_promoted_items')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('email', 255); // lowercase
            $table->unsignedInteger('discount_cop');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'released_at']);
            $table->index(['coupon_id', 'email']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('discount_cop')->constrained()->restrictOnDelete();
            $table->string('coupon_code', 30)->nullable()->after('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_code');
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
