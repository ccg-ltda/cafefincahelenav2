<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per transactional email of an order and recipient. The unique key is what keeps a repeated webhook or a re-processed
     * status from sending the same email twice. Additive: nothing existing is changed.
     */
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('recipient', 191);
            $table->string('status', 20)->default('queued'); // queued | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 200)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type', 'recipient']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
