<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-customer (email) limit was removed from coupons. `coupon_redemptions.email` stays as the history of who used a coupon.
 * down() restores the column empty (nullable): values that existed before the drop are not recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('max_uses_per_email');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('max_uses_per_email')->nullable()->after('max_uses');
        });
    }
};
