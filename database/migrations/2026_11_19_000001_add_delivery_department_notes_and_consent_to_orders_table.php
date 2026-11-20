<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable: existing orders are untouched. The two timestamps record when the customer
 * accepted the terms and the personal-data treatment (Ley 1581 de 2012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_department', 100)->nullable()->after('delivery_city');
            $table->text('order_notes')->nullable()->after('delivery_details');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('data_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_department', 'order_notes', 'terms_accepted_at', 'data_consent_at']);
        });
    }
};
