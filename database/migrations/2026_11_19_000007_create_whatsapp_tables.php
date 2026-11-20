<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. WhatsApp requests handled by the team, anonymous clicks of the storefront menu (no IP, no user agent,
 * no identifiers: only topic, page path and time) and editable quick replies. The three initial quick replies are seeded
 * here on purpose (they are part of the module, not demo data). Tables use the connection charset utf8mb4, so emojis are stored intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('topic', 20); // wholesale | order_support | buying_help
            $table->text('message');
            $table->string('status', 20)->default('new'); // new | in_progress | resolved
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['topic', 'status']);
        });

        Schema::create('whatsapp_menu_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 20);
            $table->string('page_path', 120);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'topic']);
        });

        Schema::create('whatsapp_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 20);
            $table->string('title', 120);
            $table->text('body');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['topic', 'sort_order']);
        });

        $now = now();
        DB::table('whatsapp_quick_replies')->insert([
            ['topic' => 'wholesale', 'title' => 'Mayorista', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now,
                'body' => '¡Hola! 👋 Gracias por escribir a Café Finca Helena. Para tu pedido al por mayor cuéntanos: qué cafés te interesan, cantidades aproximadas, si lo quieres en grano o molido, y la ciudad de entrega. Te enviamos precios especiales.'],
            ['topic' => 'order_support', 'title' => 'Soporte de pedido', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now,
                'body' => '¡Hola! 👋 Somos Café Finca Helena. Ya revisamos tu pedido y te contamos en un momento. ¿Nos confirmas el número de pedido (CFH-…)?'],
            ['topic' => 'buying_help', 'title' => 'Ayuda para comprar', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now,
                'body' => '¡Hola! 👋 Con gusto te ayudamos a elegir. ¿Cómo preparas tu café (greca, prensa francesa, moka, V60, espresso) y qué sabor te gusta? Te recomendamos el café y la molienda ideal.'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_quick_replies');
        Schema::dropIfExists('whatsapp_menu_clicks');
        Schema::dropIfExists('whatsapp_requests');
    }
};
