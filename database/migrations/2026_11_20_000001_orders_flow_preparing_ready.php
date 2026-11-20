<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order flow: pending -> confirmed -> preparing -> ready -> shipped -> delivered.
     * `processing` is renamed `preparing` (orders and their status history), `ready` is new, and `orders.status_changed_at`
     * says since when the order is in its current stage. `status` is a plain varchar: no CHECK or enum to extend.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'status_changed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('status_changed_at')->nullable()->after('status');
                $table->index(['status', 'status_changed_at']);
            });
        }

        DB::table('orders')->where('status', 'processing')->update(['status' => 'preparing']);
        DB::table('order_status_history')->where('from_status', 'processing')->update(['from_status' => 'preparing']);
        DB::table('order_status_history')->where('to_status', 'processing')->update(['to_status' => 'preparing']);

        // Backfill: the moment of the last recorded step, or the creation of the order when it never moved.
        DB::statement('UPDATE orders SET status_changed_at = COALESCE((SELECT MAX(h.created_at) FROM order_status_history h WHERE h.order_id = orders.id), orders.created_at) WHERE status_changed_at IS NULL');
    }

    public function down(): void
    {
        // `ready` does not exist in the old flow: those orders go back to the step before it.
        DB::table('orders')->whereIn('status', ['preparing', 'ready'])->update(['status' => 'processing']);
        DB::table('order_status_history')->whereIn('from_status', ['preparing', 'ready'])->update(['from_status' => 'processing']);
        DB::table('order_status_history')->whereIn('to_status', ['preparing', 'ready'])->update(['to_status' => 'processing']);

        if (Schema::hasColumn('orders', 'status_changed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex(['status', 'status_changed_at']);
                $table->dropColumn('status_changed_at');
            });
        }
    }
};
