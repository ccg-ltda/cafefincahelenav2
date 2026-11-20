<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual orders (the administrator registers an order that came by WhatsApp or phone). Additive:
 *  - `channel`: web (the storefront, every existing order) | manual.
 *  - `created_by`: the administrator who registered a manual order (NULL for the storefront).
 *  - `payment_due_at`: when an unpaid order is cancelled. Manual orders get it at creation (WOMPI_MANUAL_PENDING_EXPIRY_HOURS later);
 *    storefront orders keep NULL and expire by `created_at` + WOMPI_PENDING_EXPIRY_MINUTES as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 16)->default('web')->after('reference');
            $table->foreignId('created_by')->nullable()->after('channel')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_due_at')->nullable();
            $table->index(['channel', 'created_at']);
            $table->index('payment_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel', 'created_at']);
            $table->dropIndex(['payment_due_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['channel', 'payment_due_at']);
        });
    }
};
