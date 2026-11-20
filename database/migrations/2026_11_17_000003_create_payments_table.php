<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('reference', 100);
            $table->string('external_transaction_id', 191)->nullable();
            $table->string('status', 40)->default('pending');
            $table->unsignedBigInteger('amount_cop');
            $table->char('currency', 3)->default('COP');
            $table->timestamp('confirmed_at')->nullable();
            // Only a trusted server-side provider check may populate this field.
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'reference']);
            $table->unique(['provider', 'external_transaction_id']);
            $table->index(['status', 'confirmed_at', 'order_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency = 'COP')");
        }
    }

    public function down(): void { Schema::dropIfExists('payments'); }
};
