<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\InventoryService;
use App\Support\ActivityDiff;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProductEditorController extends Controller
{
    private const VARIANT_FIELDS = ['name', 'price_cop', 'weight_grams', 'grind_type', 'is_published'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function show(Product $product): JsonResponse
    {
        $product->load(['variants' => fn ($query) => $query->orderBy('id')]);
        return response()->json(['product' => $this->productData($product)])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Creates a product. With `simple=1` the single presentation (SKU, price, image, …) is validated
     * and stored in the same transaction, so no empty product is ever left behind.
     */
    public function store(Request $request): JsonResponse
    {
        $simple = $request->boolean('simple');
        $rules = $this->productRules();
        if ($simple) {
            foreach ($this->variantRules(new ProductVariant()) as $field => $rule) {
                $rules['variant.'.$field] = $rule;
            }
        }
        $validated = $request->validate($rules, $this->messages(), $this->attributes());
        $data = collect($validated)->only(['name', 'category_id', 'short_description', 'description', 'is_published'])->all();
        $variantData = null;
        if ($simple) {
            $variantData = $validated['variant'];
            unset($variantData['image']);
            if ($request->hasFile('variant.image')) {
                $path = $request->file('variant.image')->store('products', 'public');
                if ($path === false) throw new \RuntimeException('No se pudo almacenar la imagen.');
                $variantData['image_path'] = $path;
            }
        }

        $product = $this->createProduct($data, $variantData);
        return response()->json(['product' => $this->productData($product->load(['variants' => fn ($query) => $query->orderBy('id')]))], 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate($this->productRules($product), $this->messages());
        DB::transaction(function () use ($product, $data) {
            $product->fill($data);
            $changes = ActivityDiff::dirty($product);
            $product->save();
            if ($changes) {
                $this->activity->log('products', 'updated', $product, $changes);
            }
        });
        return response()->json(['product' => $this->productData($product->fresh()->load('variants'))])->header('Cache-Control', 'private, no-store');
    }

    public function storeVariant(Request $request, Product $product): JsonResponse
    {
        return $this->saveVariant($request, $product, new ProductVariant());
    }

    public function updateVariant(Request $request, Product $product, int $variant): JsonResponse
    {
        return $this->saveVariant($request, $product, $product->variants()->findOrFail($variant));
    }

    /**
     * Activates or deactivates one presentation (`product_variants.is_published`) and nothing else.
     * The variant is looked up through the product, so a variant of another product is a 404.
     */
    public function variantStatus(Request $request, Product $product, int $variant): JsonResponse
    {
        $variant = $product->variants()->findOrFail($variant);
        $data = $request->validate(['is_published' => ['required', 'boolean']]);
        DB::transaction(function () use ($variant, $product, $data) {
            $variant->fill(['is_published' => $data['is_published']]);
            $changed = $variant->isDirty('is_published');
            $variant->save();
            if ($changed) {
                $this->activity->log('products', $variant->is_published ? 'activated' : 'deactivated', $variant->setRelation('product', $product));
            }
        });
        return response()->json(['product' => $this->productData($product->fresh()->load(['variants' => fn ($query) => $query->orderBy('id')]))])->header('Cache-Control', 'private, no-store');
    }

    private function messages(): array
    {
        return ['category_id.exists' => 'Selecciona una categoría activa válida.'];
    }

    private function attributes(): array
    {
        $names = ['stock_quantity' => 'stock', 'expected_stock' => 'stock original'];

        return $names + collect($names)->mapWithKeys(fn ($name, $key) => ['variant.'.$key => $name])->all();
    }

    private function productRules(?Product $product = null): array
    {
        // New assignments must target an active category; a product may keep its current one even if inactive.
        $currentCategory = $product?->category_id;
        $category = Rule::exists('categories', 'id')->where(fn ($query) => $query->where(
            fn ($group) => $group->where('is_active', true)->when($currentCategory, fn ($inner) => $inner->orWhere('id', $currentCategory))
        ));

        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', $category],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_published' => ['required', 'boolean'],
        ];
    }

    private function variantRules(ProductVariant $variant): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'price_cop' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'weight_grams' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'grind_type' => ['nullable', 'string', 'max:100'],
            'is_published' => ['required', 'boolean'],
            // Creating: optional initial stock. Editing: exact stock plus the stock the form started from (see InventoryService::setStock).
            ...($variant->exists ? [
                'stock_quantity' => ['required_with:expected_stock', 'integer', 'min:0', 'max:1000000'],
                'expected_stock' => ['required_with:stock_quantity', 'integer', 'min:0', 'max:4294967295'],
            ] : ['stock_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000']]),
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }

    private function createProduct(array $data, ?array $variantData): Product
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $data['slug'] = $this->uniqueSlug($data['name'], $attempt > 0);
            try {
                return DB::transaction(function () use ($data, $variantData) {
                    $product = Product::create($data);
                    $variant = $variantData ? $this->createVariantWithSku($product, $variantData, null, false) : null;
                    $this->activity->log('products', 'created', $product, ActivityDiff::between([], $data), $variant ? ['sku' => $variant->sku, 'variant' => $variant->name] : []);
                    return $product;
                });
            } catch (QueryException $error) {
                if ((string) $error->getCode() !== '23000') throw $error;
                // The slug was taken concurrently: retry with a random suffix.
            }
        }
        throw new \RuntimeException('No se pudo generar una URL única para el producto.');
    }

    /** Clean public slug from the name (lowercase, no accents); never reuses a slug, redirect or SKU URL. */
    private function uniqueSlug(string $name, bool $randomSuffix = false): string
    {
        $base = Str::limit(Str::slug($name), 180, '') ?: 'producto';
        $redirects = Schema::hasTable('product_slug_redirects');
        $taken = fn (string $slug) => Product::where('slug', $slug)->exists()
            || ProductVariant::whereRaw('lower(sku) = ?', [$slug])->exists()
            || ($redirects && DB::table('product_slug_redirects')->where('slug', $slug)->exists());
        $slug = $randomSuffix ? $base.'-'.Str::lower(Str::random(5)) : $base;
        for ($number = 2; $taken($slug); $number++) {
            $slug = $base.'-'.$number;
        }
        return $slug;
    }

    /**
     * Next SKU in the existing FH-### sequence. It takes the highest number found in product_variants and in
     * order_items (which keeps the SKU of every sold item), so a number is not reused while a record carries it.
     * Only the backend assigns SKUs.
     */
    private function nextSku(): string
    {
        $pattern = '^FH-[0-9]+$';
        $highest = fn (string $table) => (int) DB::table($table)->whereRaw('sku REGEXP ?', [$pattern])->selectRaw('MAX(CAST(SUBSTRING(sku, 4) AS UNSIGNED)) as n')->value('n');
        $ordered = Schema::hasTable('order_items') ? $highest('order_items') : 0;
        return sprintf('FH-%03d', max($highest('product_variants'), $ordered) + 1);
    }

    /**
     * Inserts a new variant with a generated SKU. The unique index on product_variants.sku guarantees there is
     * no duplicate: if a concurrent request took the same number the insert fails and a fresh number is taken.
     */
    private function createVariantWithSku(Product $product, array $data, ?ProductVariant $variant = null, bool $log = true): ProductVariant
    {
        $variant ??= new ProductVariant();
        $initialStock = (int) ($data['stock_quantity'] ?? 0);
        unset($data['stock_quantity']);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $sku = $this->nextSku();
            try {
                return DB::transaction(function () use ($variant, $product, $data, $sku, $initialStock, $log) {
                    $variant->fill($data);
                    $variant->sku = $sku;
                    $variant->product()->associate($product);
                    $variant->save();
                    app(InventoryService::class)->initialStock($variant, $initialStock, auth()->id());
                    if ($log) {
                        $this->activity->log('products', 'created', $variant, ActivityDiff::between([], collect($data)->only(self::VARIANT_FIELDS)->all() + ['stock_quantity' => $initialStock]), ['sku' => $sku]);
                    }
                    return $variant;
                });
            } catch (QueryException $error) {
                if ((string) $error->getCode() !== '23000' || ! ProductVariant::where('sku', $sku)->exists()) throw $error;
                $variant->exists = false;
            }
        }
        throw new \RuntimeException('No se pudo asignar un SKU único.');
    }

    private function saveVariant(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $data = $request->validate($this->variantRules($variant), [], $this->attributes());
        unset($data['image']);
        // Stock of an existing variant never goes through fill(): it changes only as an InventoryService movement.
        $stock = null;
        if ($variant->exists) {
            if (array_key_exists('stock_quantity', $data)) {
                $stock = ['quantity' => (int) $data['stock_quantity'], 'expected' => (int) $data['expected_stock']];
            }
            unset($data['stock_quantity'], $data['expected_stock']);
        }
        $path = null;
        try {
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('products', 'public');
                if ($path === false) throw new \RuntimeException('No se pudo almacenar la imagen.');
                $data['image_path'] = $path;
            }
            $created = ! $variant->exists;
            if ($created) {
                $this->createVariantWithSku($product, $data, $variant);
            } else {
                // The SKU of an existing variant is never edited or regenerated.
                DB::transaction(function () use ($variant, $data, $stock, $request, $product) {
                    // A stock conflict (409) aborts before anything else is saved.
                    if ($stock) app(InventoryService::class)->setStock($variant->id, $stock['quantity'], $stock['expected'], 'Edición de producto', auth()->id());
                    $variant->fill($data);
                    $changes = ActivityDiff::dirty($variant, self::VARIANT_FIELDS);
                    $variant->save();
                    // Stock has its own "inventory" row (InventoryService::setStock); this one follows the other fields.
                    if ($changes || $request->hasFile('image')) {
                        $this->activity->log('products', 'updated', $variant->setRelation('product', $product), $changes, ['sku' => $variant->sku] + ($request->hasFile('image') ? ['image' => 'Imagen reemplazada'] : []));
                    }
                });
            }
        } catch (\Throwable $error) {
            // Keep any uploaded file: a failed commit can have an uncertain outcome.
            throw $error;
        }
        return response()->json(['product' => $this->productData($product->fresh()->load('variants'))], $created ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    private function productData(Product $product): array
    {
        return [
            'id' => $product->id, 'name' => $product->name, 'category_id' => $product->category_id,
            'short_description' => $product->short_description, 'description' => $product->description, 'is_published' => $product->is_published,
            'variants' => $product->variants->map(fn ($variant) => [
                'id' => $variant->id, 'sku' => $variant->sku, 'name' => $variant->name,
                'price_cop' => $variant->price_cop, 'weight_grams' => $variant->weight_grams, 'grind_type' => $variant->grind_type,
                'is_published' => $variant->is_published,
                'stock_quantity' => $variant->stock_quantity, 'low_stock_threshold' => $variant->low_stock_threshold,
                'image_url' => $variant->image_path ? Storage::disk('public')->url($variant->image_path) : null,
            ])->values(),
        ];
    }
}
