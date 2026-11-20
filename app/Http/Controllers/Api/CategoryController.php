<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\PromotionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->where('is_active', true)->orderBy('id')->get()
        )->additional(['meta' => ['has_promotions' => $this->promotions->hasCurrent()]]);
    }
}
