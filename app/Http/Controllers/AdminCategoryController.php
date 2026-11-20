<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ActivityLogger;
use App\Support\ActivityDiff;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCategoryController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): JsonResponse
    {
        // Counts rows of `products` (main products), never `product_variants`.
        $categories = Category::query()->withCount('products')->orderBy('name')->orderBy('id')->get();

        return $this->respond(['data' => $categories->map(fn (Category $category) => $this->categoryData($category))->values()]);
    }

    public function show(Category $category): JsonResponse
    {
        return $this->respond(['category' => $this->categoryData($category->loadCount('products'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')],
            'is_active' => ['required', 'boolean'],
        ]);
        $name = trim($data['name']);
        $slug = $this->uniqueSlug($name);

        try {
            $category = DB::transaction(function () use ($name, $slug, $data) {
                $category = Category::create(['name' => $name, 'slug' => $slug, 'is_active' => $data['is_active']]);
                $this->activity->log('categories', 'created', $category, ActivityDiff::between([], ['name' => $name, 'is_active' => $data['is_active']]));

                return $category;
            });
        } catch (QueryException $error) {
            $this->rethrowDuplicate($error);
        }

        return $this->respond(['category' => $this->categoryData($category->loadCount('products'))], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)],
            'is_active' => ['required', 'boolean'],
        ]);
        $name = trim($data['name']);
        // The slug is a public URL key: it is validated against other categories but never rewritten.
        $this->assertSlugAvailable($name, $category->id);

        try {
            DB::transaction(function () use ($category, $name, $data) {
                $category->fill(['name' => $name, 'is_active' => $data['is_active']]);
                $changes = ActivityDiff::dirty($category);
                $category->save();
                if ($changes) {
                    $this->activity->log('categories', 'updated', $category, $changes);
                }
            });
        } catch (QueryException $error) {
            $this->rethrowDuplicate($error);
        }

        return $this->respond(['category' => $this->categoryData($category->loadCount('products'))]);
    }

    public function status(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        // Only the category flag changes: products, variants and images are untouched.
        DB::transaction(function () use ($category, $data) {
            $category->fill(['is_active' => $data['is_active']]);
            $changed = $category->isDirty('is_active');
            $category->save();
            if ($changed) {
                $this->activity->log('categories', $category->is_active ? 'activated' : 'deactivated', $category);
            }
        });

        return $this->respond(['category' => $this->categoryData($category->loadCount('products'))]);
    }

    private function uniqueSlug(string $name): string
    {
        $slug = Str::limit(Str::slug($name), 255, '');
        $this->assertSlugAvailable($name, null, $slug);

        return $slug;
    }

    private function assertSlugAvailable(string $name, ?int $ignoreId, ?string $slug = null): void
    {
        $slug ??= Str::slug($name);
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => ['El nombre debe contener letras o números.']]);
        }
        $taken = Category::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => ['Ya existe una categoría con un nombre equivalente.']]);
        }
    }

    private function rethrowDuplicate(QueryException $error): never
    {
        if ((string) $error->getCode() === '23000') {
            throw ValidationException::withMessages(['name' => ['Ya existe una categoría con un nombre equivalente.']]);
        }
        throw $error;
    }

    private function categoryData(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'is_active' => $category->is_active,
            'products_count' => (int) $category->products_count,
        ];
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
