<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A manual order may have no email (the customer only has a phone). The storefront still requires one (StoreOrderRequest).
 * `customers.email` keeps its unique index: MySQL allows many NULLs. A customer without email is identified by phone.
 * Every existing row keeps its value. The rollback refuses to run while rows without email exist: it would have to invent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->change();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNull('customer_email')->exists() || DB::table('customers')->whereNull('email')->exists()) {
            throw new RuntimeException('Hay pedidos o clientes sin correo: no se puede volver a exigirlo sin inventar uno.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable(false)->change();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
