<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Audit trail: rows are only ever inserted (the model refuses update/delete; only `activity:prune` removes old "auth" rows). */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label', 191)->nullable();
            $table->string('module', 30);
            $table->string('action', 30);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 191)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 36)->nullable();

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['module', 'action', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
