<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Services\AdminProductListing;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly AdminProductListing $listing, private readonly InventoryService $inventory, private readonly ActivityLogger $activity) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive', 'low', 'out'])],
            // Old URLs: ?publication=published|unpublished means status=active|inactive.
            'publication' => ['sometimes', 'nullable', Rule::in(['published', 'unpublished'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $status = $filters['status'] ?? match ($filters['publication'] ?? null) { 'published' => 'active', 'unpublished' => 'inactive', default => null };

        $query = $this->listing->query();

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(function (Builder $query) use ($search) {
                $query->where('products.name', 'like', '%'.$search.'%')
                    ->orWhereHas('variants', fn (Builder $variants) => $variants->where(function (Builder $variants) use ($search) {
                        $variants->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%');
                    }));
            });
        }
        if (filled($filters['category'] ?? null)) {
            $query->where('products.category_id', $filters['category']);
        }
        match ($status) {
            'active' => $query->where('products.is_published', true),
            'inactive' => $query->where('products.is_published', false),
            'low', 'out' => $this->inventory->filterProducts($query, $status),
            default => null,
        };

        $page = $query->orderBy('products.sort_order')->orderBy('products.id')
            ->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->listing->row($row)),
            'summary' => $this->inventory->summary(),
            'categories' => Category::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active']),
            'catalog_empty' => ! Product::query()->exists(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Activates or deactivates the main product (`products.is_published`) and nothing else:
     * variants, categories, images, SKUs and prices are not touched.
     */
    public function status(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['is_published' => ['required', 'boolean']]);
        DB::transaction(function () use ($product, $data) {
            $product->fill(['is_published' => $data['is_published']]);
            $changed = $product->isDirty('is_published');
            $product->save();
            if ($changed) {
                $this->activity->log('products', $product->is_published ? 'activated' : 'deactivated', $product);
            }
        });

        return response()->json(['data' => $this->listing->find($product->id), 'summary' => $this->inventory->summary()])
            ->header('Cache-Control', 'private, no-store');
    }
}
