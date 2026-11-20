<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 100)->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40);
            $table->text('delivery_address');
            $table->text('delivery_details')->nullable();
            $table->string('delivery_city');
            $table->unsignedBigInteger('subtotal_cop');
            $table->unsignedBigInteger('shipping_cop')->default(0);
            $table->unsignedBigInteger('discount_cop')->default(0);
            $table->unsignedBigInteger('total_cop');
            $table->char('currency', 3)->default('COP');
            $table->string('status', 40)->default('pending');
            $table->string('payment_status', 40)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['customer_email', 'created_at']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_amounts_check CHECK (currency = 'COP' AND discount_cop <= subtotal_cop + shipping_cop AND total_cop + discount_cop = subtotal_cop + shipping_cop)");
        }
    }

    public function down(): void { Schema::dropIfExists('orders'); }
};
