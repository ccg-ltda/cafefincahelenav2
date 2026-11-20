<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: stores slugs that were replaced so their public URLs keep answering with a 301,
 * and moves the three simple products to the short descriptive slugs. Nothing is deleted;
 * a slug is changed only when the product still has the expected old slug and the new one is free.
 */
return new class extends Migration
{
    private const RENAMES = [
        'kit-degustacion-2-125-g' => 'kit-degustacion',
        'combo-familiar-250-125-g' => 'combo-familiar',
        'pack-grano-premium-500-g' => 'pack-grano-premium',
    ];

    public function up(): void
    {
        Schema::create('product_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        foreach (self::RENAMES as $old => $new) {
            DB::transaction(function () use ($old, $new) {
                $product = DB::table('products')->where('slug', $old)->lockForUpdate()->first();
                if (! $product || DB::table('products')->where('slug', $new)->exists()) {
                    return;
                }
                DB::table('product_slug_redirects')->insert(['slug' => $old, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('products')->where('id', $product->id)->update(['slug' => $new]);
            });
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $redirect = DB::table('product_slug_redirects')->where('slug', $old)->first();
            if ($redirect && ! DB::table('products')->where('slug', $old)->exists()) {
                DB::table('products')->where('id', $redirect->product_id)->where('slug', $new)->update(['slug' => $old]);
            }
        }
        Schema::dropIfExists('product_slug_redirects');
    }
};
