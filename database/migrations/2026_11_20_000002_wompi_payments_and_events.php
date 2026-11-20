<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wompi payments. Additive: nothing existing is changed or deleted.
     *
     * - payments: what Wompi reports about each attempt (method, message), until when the customer may pay it, and the alert
     *   "approved payment on a cancelled order": `review_required_at` is set by PaymentService when that happens and stays an
     *   active alert until an administrator marks it reviewed (`reviewed_at` + `reviewed_by`; the Historial keeps who and when).
     * - payment_events: one row per webhook received, keyed by its checksum, so the same event twice is recognised and does
     *   nothing. It stores no customer data (the raw body is never kept) and no secret.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_method_type', 40)->nullable()->after('status');
            $table->string('status_message', 255)->nullable()->after('payment_method_type');
            $table->timestamp('checkout_expires_at')->nullable()->after('status_message');
            $table->timestamp('review_required_at')->nullable()->after('verified_at');
            $table->timestamp('reviewed_at')->nullable()->after('review_required_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->index(['review_required_at', 'reviewed_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('checksum', 64);
            $table->string('event', 60);
            $table->string('external_transaction_id', 191)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedBigInteger('amount_in_cents')->nullable();
            $table->string('outcome', 40); // applied | duplicate | ignored | mismatch | unknown_reference
            $table->timestamp('created_at')->nullable();
            $table->unique(['provider', 'checksum']);
            $table->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['review_required_at', 'reviewed_at']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['payment_method_type', 'status_message', 'checkout_expires_at', 'review_required_at', 'reviewed_at', 'reviewed_by']);
        });
    }
};
