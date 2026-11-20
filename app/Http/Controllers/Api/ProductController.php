<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\PromotionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validator = Validator::make($request->query(), [
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'search' => ['sometimes', 'required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Los parámetros proporcionados no son válidos.',
                'errors' => $validator->errors(),
            ], 422));
        }

        $filters = $validator->validated();
        $query = $this->visibleProducts();

        if (isset($filters['category'])) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $products) use ($search) {
                $products->where('name', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $products = $query->orderBy('sort_order')->orderBy('id')->get();
        $this->promotions->attachPricing($products);

        // `has_promotions` tells the storefront whether the "Promociones" tab exists.
        return ProductResource::collection($products)->additional(['meta' => ['has_promotions' => $this->promotions->hasCurrent()]]);
    }

    public function show(string $slug): ProductResource
    {
        $product = $this->visibleProducts()->where('slug', $slug)->firstOrFail();
        $this->promotions->attachPricing([$product]);

        return new ProductResource($product);
    }

    private function visibleProducts(): Builder
    {
        return Product::query()
            ->visible()
            ->with([
                'category',
                'variants' => fn ($variants) => $variants->where('is_published', true)->orderBy('id'),
            ]);
    }
}
