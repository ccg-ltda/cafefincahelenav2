<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. A customer is identified by its (lowercase, trimmed) email. Order counts, spending and first/last purchase
 * are NOT stored: they are computed from `orders`, so they can never go out of sync.
 * `orders.customer_id` is RESTRICT: a customer with orders cannot be deleted.
 * `orders.marketing_opt_in` is what the buyer ticked in that order (Ley 1581 evidence); existing orders stay false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('city');
            $table->string('department', 100)->nullable();
            $table->text('last_address')->nullable();
            $table->text('last_address_details')->nullable();
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamp('marketing_opt_out_at')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->boolean('marketing_opt_in')->default(false)->after('data_consent_at');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('marketing_opt_in');
        });
        Schema::dropIfExists('customers');
    }
};
