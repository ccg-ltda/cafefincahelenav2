<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePromotionRequest;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\ActivityLogger;
use App\Services\PromotionService;
use App\Support\ActivityDiff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminPromotionController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly PromotionService $promotions, private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['current', 'scheduled', 'expired', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);

        $query = $this->query();
        if (filled($filters['search'] ?? null)) {
            $query->whereHas('product', fn (Builder $product) => $product->where('name', 'like', '%'.trim($filters['search']).'%'));
        }
        if (filled($filters['status'] ?? null)) {
            $query->inStatus($filters['status']);
        }

        $page = $query->orderByDesc('promotions.id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Promotion $promotion) => $this->row($promotion))->values(),
            'summary' => $this->promotions->summary(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    /** Published products (with their published variants) for the product select of the form. */
    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $products = Product::query()->where('is_published', true)
            ->whereHas('variants', fn (Builder $variants) => $variants->where('is_published', true))
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->with(['variants' => fn ($variants) => $variants->where('is_published', true)->orderBy('id')])
            ->orderBy('name')->limit(30)->get();

        return response()->json(['data' => $products->map(fn (Product $product) => $this->productOption($product))->values()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $promotion = $this->query()->findOrFail($promotion->id);
        $product = Product::query()->with(['variants' => fn ($variants) => $variants->where('is_published', true)->orderBy('id')])->findOrFail($promotion->product_id);

        return response()->json(['data' => $this->row($promotion) + ['variant_ids' => $promotion->variants->pluck('id')->values(), 'product_option' => $this->productOption($product)]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(SavePromotionRequest $request): JsonResponse
    {
        $payload = $this->payload($request->validated());
        $this->promotions->validateForSave($payload);

        $promotion = DB::transaction(function () use ($payload, $request) {
            $promotion = Promotion::create($this->attributes($payload) + ['created_by' => $request->user()->id]);
            $promotion->variants()->sync($payload['variant_ids']);
            $this->activity->log('promotions', 'created', $promotion->load('product'), ActivityDiff::between([], $this->auditFields($payload)));

            return $promotion;
        });

        return response()->json(['data' => $this->row($this->query()->findOrFail($promotion->id)), 'summary' => $this->promotions->summary()], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(SavePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $payload = $this->payload($request->validated());
        $this->promotions->validateForSave($payload, $promotion->id);

        DB::transaction(function () use ($payload, $promotion) {
            $before = $this->auditFields(['variant_ids' => $promotion->variants()->pluck('product_variants.id')->all()] + $promotion->getAttributes());
            $promotion->fill($this->attributes($payload));
            $promotion->save();
            $promotion->variants()->sync($payload['variant_ids']);
            $changes = ActivityDiff::between($before, $this->auditFields($payload));
            if ($changes) {
                $this->activity->log('promotions', 'updated', $promotion->load('product'), $changes);
            }
        });

        return response()->json(['data' => $this->row($this->query()->findOrFail($promotion->id)), 'summary' => $this->promotions->summary()])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Activates or deactivates. Activating re-checks prices and overlaps, like a save. */
    public function status(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        if ($data['is_active']) {
            $this->promotions->validateForSave([
                'product_id' => $promotion->product_id, 'variant_ids' => $promotion->variants()->pluck('product_variants.id')->all(),
                'discount_type' => $promotion->discount_type, 'discount_value' => $promotion->discount_value, 'is_active' => true,
                'starts_at' => $promotion->starts_at, 'ends_at' => $promotion->ends_at,
            ], $promotion->id);
        }
        DB::transaction(function () use ($promotion, $data) {
            $promotion->fill(['is_active' => $data['is_active']]);
            $changed = $promotion->isDirty('is_active');
            $promotion->save();
            if ($changed) {
                $this->activity->log('promotions', $promotion->is_active ? 'activated' : 'deactivated', $promotion->load('product'));
            }
        });

        return response()->json(['data' => $this->row($this->query()->findOrFail($promotion->id)), 'summary' => $this->promotions->summary()])
            ->header('Cache-Control', 'private, no-store');
    }

    /** A promotion that was used in an order cannot be deleted (it can be deactivated). */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $usedMessage = 'Esta promoción ya se usó en pedidos; puedes desactivarla.';
        if ($promotion->orderItems()->exists()) {
            return response()->json(['message' => $usedMessage], 409);
        }

        try {
            DB::transaction(function () use ($promotion) {
                $this->activity->log('promotions', 'deleted', $promotion->load('product'));
                $promotion->delete();
            });
        } catch (QueryException $error) {
            // An order used it between the check and the delete: the foreign key (RESTRICT) refuses.
            if ((string) $error->getCode() === '23000') {
                return response()->json(['message' => $usedMessage], 409);
            }
            throw $error;
        }

        return response()->json(['summary' => $this->promotions->summary()])->header('Cache-Control', 'private, no-store');
    }

    private function query(): Builder
    {
        return Promotion::query()->with(['product.category', 'product.variants', 'variants'])->withExists(['orderItems as used_in_orders']);
    }

    /** @return array{product_id: int, variant_ids: list<int>, discount_type: string, discount_value: int, is_active: bool, starts_at: ?\Carbon\Carbon, ends_at: ?\Carbon\Carbon} */
    private function payload(array $data): array
    {
        return [
            'product_id' => (int) $data['product_id'],
            'variant_ids' => array_values(array_unique(array_map('intval', $data['variant_ids']))),
            'discount_type' => $data['discount_type'],
            'discount_value' => (int) $data['discount_value'],
            'is_active' => (bool) $data['is_active'],
            'starts_at' => $this->promotions->parseLocal($data['starts_at'] ?? null),
            'ends_at' => $this->promotions->parseLocal($data['ends_at'] ?? null),
        ];
    }

    /** The fields the audit trail follows, with the same shape whether they come from the request or from the stored row. */
    private function auditFields(array $source): array
    {
        $date = fn ($value) => $value === null ? null : ($value instanceof \DateTimeInterface ? $value : Carbon::parse($value, 'UTC'));
        $variants = collect($source['variant_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();

        return [
            'product_id' => (int) $source['product_id'], 'discount_type' => $source['discount_type'], 'discount_value' => (int) $source['discount_value'],
            'is_active' => (bool) $source['is_active'], 'starts_at' => $date($source['starts_at'] ?? null), 'ends_at' => $date($source['ends_at'] ?? null),
            'variant_ids' => implode(', ', $variants),
        ];
    }

    private function attributes(array $payload): array
    {
        return collect($payload)->only(['product_id', 'discount_type', 'discount_value', 'is_active', 'starts_at', 'ends_at'])->all();
    }

    private function productOption(Product $product): array
    {
        return [
            'id' => $product->id, 'name' => $product->name,
            'variants' => $product->variants->map(fn ($variant) => [
                'id' => $variant->id, 'name' => $variant->name, 'weight_grams' => $variant->weight_grams,
                'grind_type' => $variant->grind_type, 'price_cop' => $variant->price_cop,
            ])->values(),
        ];
    }

    private function row(Promotion $promotion): array
    {
        $status = $this->promotions->status($promotion);
        $product = $promotion->product;
        $variants = $promotion->variants->sortBy('id')->values();
        $type = $promotion->discount_type;
        $value = $promotion->discount_value;
        $promoPrice = fn ($variant) => $this->promotions->priceFor((int) $variant->price_cop, $type, $value);
        $image = $product->variants->first(fn ($variant) => filled($variant->image_path))?->image_path;

        // A scheduled or current promotion that the store cannot show: say why.
        $note = null;
        if (in_array($status, ['current', 'scheduled'], true)) {
            $note = match (true) {
                ! $product->is_published => 'product_inactive',
                ! $product->category->is_active => 'category_inactive',
                $variants->where('is_published', true)->isEmpty() => 'variants_inactive',
                default => null,
            };
        }

        return [
            'id' => $promotion->id,
            'product' => ['id' => $product->id, 'name' => $product->name, 'published' => $product->is_published, 'image_url' => $image ? Storage::disk('public')->url($image) : null],
            'variants' => $variants->map(fn ($variant) => [
                'id' => $variant->id, 'name' => $variant->name, 'weight_grams' => $variant->weight_grams, 'grind_type' => $variant->grind_type,
                'price_cop' => $variant->price_cop, 'promo_price_cop' => $promoPrice($variant), 'published' => $variant->is_published,
            ])->all(),
            'discount_type' => $type, 'discount_value' => $value, 'discount_label' => $this->promotions->label($type, $value),
            'base_min_cop' => $variants->min('price_cop'), 'base_max_cop' => $variants->max('price_cop'),
            'promo_min_cop' => $variants->map($promoPrice)->min(), 'promo_max_cop' => $variants->map($promoPrice)->max(),
            'starts_at' => $promotion->starts_at?->toIso8601String(), 'ends_at' => $promotion->ends_at?->toIso8601String(),
            'starts_at_local' => $this->promotions->formatLocal($promotion->starts_at), 'ends_at_local' => $this->promotions->formatLocal($promotion->ends_at),
            'is_active' => $promotion->is_active, 'status' => $status, 'note' => $note,
            'used_in_orders' => (bool) $promotion->used_in_orders,
        ];
    }
}
