<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Initial catalogue (REAL data, it goes to production). It only CREATES what is missing and never overwrites anything:
 * prices, names, descriptions, publication, images and stock that the admin edited stay as they are, so it can be run again
 * (for example on an update) without side effects.
 *
 * - A product is found by its slug, by a replaced slug (`product_slug_redirects`) or by the SKU of one of its variants, so a
 *   product renamed by the admin or by the slug migration is never duplicated.
 * - A variant is found by SKU (unique). An existing one is left untouched.
 * - Stock is NOT seeded: new variants start at 0 ("Agotado") and the admin loads the real quantities (inventory movement "ajuste").
 * - Images live in database/seeders/images/products (in git) and are copied to the `public` disk only when the file is missing.
 */
class ProductSeeder extends Seeder
{
    /** Where the initial images are kept in the repository. */
    private const IMAGES = __DIR__.'/images';

    public function run(): void
    {
        $cafe = Category::where('slug', 'cafe')->firstOrFail();

        $products = [
            [
                'category_id' => $cafe->id,
                'name' => 'Café Premium',
                'slug' => 'cafe-premium',
                'description' => implode("\n", [
                    'Molido 125 gr: tamaño ideal para probar. Para goteo, prensa o greca.',
                    'Molienda media para el café de todos los días en goteo o greca.',
                    'Molido 340 gr, rinde para varias semanas. Notas florales y a madera.',
                    'Bolsa de 500 gr para la familia o la oficina. Para goteo, prensa o greca.',
                    '2.5 Kg para cafeterías, oficinas y eventos. Molienda media pareja.',
                    'Grano entero 125 gr. Muela al momento y conserve todo el aroma.',
                    'Grano entero para molino casero, espresso o prensa francesa.',
                    'Grano de 340 gr, tostión media-baja. Equilibrio entre suavidad y acidez.',
                    'Grano entero 500 gr para quienes muelen cada mañana.',
                    '2.5 Kg en grano para barras de café y molinos profesionales.',
                ]),
                'variants' => [
                    ['FH-001', 'Café Premium 125 g Molido', 125, 'Molido', 19900, 'products/cafe-125-m.webp'],
                    ['FH-002', 'Café Premium 250 g Molido', 250, 'Molido', 37900, 'products/cafe-250-m.webp'],
                    ['FH-003', 'Café Premium 340 g Molido', 340, 'Molido', 45900, 'products/cafe-340-m.webp'],
                    ['FH-004', 'Café Premium 500 g Molido', 500, 'Molido', 60900, 'products/cafe-500-m.webp'],
                    ['FH-005', 'Café Premium 2.5 kg Molido', 2500, 'Molido', 265900, 'products/cafe-25k-m.webp'],
                    ['FH-006', 'Café Premium 125 g Grano entero', 125, 'Grano entero', 19900, 'products/cafe-125-t.webp'],
                    ['FH-007', 'Café Premium 250 g Grano entero', 250, 'Grano entero', 37900, 'products/cafe-250-t.webp'],
                    ['FH-008', 'Café Premium 340 g Grano entero', 340, 'Grano entero', 45900, 'products/cafe-340-t.webp'],
                    ['FH-009', 'Café Premium 500 g Grano entero', 500, 'Grano entero', 60900, 'products/cafe-500-t.webp'],
                    ['FH-010', 'Café Premium 2.5 kg Grano entero', 2500, 'Grano entero', 265900, 'products/cafe-25k-t.webp'],
                ],
            ],
            [
                'category_id' => $cafe->id,
                'name' => 'Kit Degustación — 2 x 125 gr',
                'slug' => 'kit-degustacion',
                'description' => 'Dos bolsas de 125 gr molido para regalar o compartir.',
                'variants' => [
                    ['FH-011', 'Kit Degustación 2 × 125 g Molido', 250, 'Molido', 34900, 'products/cafe-125-m.webp'],
                ],
            ],
            [
                'category_id' => $cafe->id,
                'name' => 'Combo Familiar — 250 gr + 125 gr',
                'slug' => 'combo-familiar',
                'description' => 'Molido 250 gr + 125 gr. El café de la casa con descuento.',
                'variants' => [
                    ['FH-012', 'Combo Familiar 250 g + 125 g Molido', 375, 'Molido', 49900, 'products/cafe-250-m.webp'],
                ],
            ],
            [
                'category_id' => $cafe->id,
                'name' => 'Pack Grano Premium — 500 gr',
                'slug' => 'pack-grano-premium',
                'description' => 'Grano entero 500 gr a precio especial. Ideal para espresso.',
                'variants' => [
                    ['FH-013', 'Pack Grano Premium 500 g', 500, 'Grano entero', 54900, 'products/cafe-500-t.webp'],
                ],
            ],
        ];

        foreach ($products as $order => $data) {
            $variants = $data['variants'];
            unset($data['variants']);

            $product = $this->findProduct($data['slug'], array_column($variants, 0))
                ?? Product::create($data + ['short_description' => null, 'is_published' => true, 'sort_order' => $order]);

            foreach ($variants as [$sku, $name, $weight, $grind, $price, $image]) {
                $this->copyImage($image);
                if (ProductVariant::where('sku', $sku)->exists()) {
                    continue; // already there (maybe edited by the admin): never overwritten
                }
                ProductVariant::create([
                    'product_id' => $product->id, 'sku' => $sku, 'name' => $name, 'weight_grams' => $weight, 'grind_type' => $grind,
                    'price_cop' => $price, 'image_path' => $image, 'is_published' => true,
                ]);
            }
        }
    }

    /** By slug, by a replaced slug (redirect) or by the product that already owns one of the SKUs. */
    private function findProduct(string $slug, array $skus): ?Product
    {
        if ($product = Product::where('slug', $slug)->first()) {
            return $product;
        }
        if ($redirected = DB::table('product_slug_redirects')->where('slug', $slug)->value('product_id')) {
            return Product::find($redirected);
        }
        $owner = ProductVariant::whereIn('sku', $skus)->value('product_id');

        return $owner ? Product::find($owner) : null;
    }

    /** Copies a seed image to the `public` disk only if it is not there yet (an image the admin replaced is never touched). */
    private function copyImage(string $path): void
    {
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return;
        }
        $source = self::IMAGES.'/'.$path;
        if (! is_file($source)) {
            $this->command?->warn("Falta la imagen semilla {$source}: el producto saldrá sin imagen.");

            return;
        }
        $disk->put($path, file_get_contents($source));
    }
}
