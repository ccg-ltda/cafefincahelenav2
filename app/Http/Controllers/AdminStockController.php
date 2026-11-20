<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdjustStockRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\AdminProductListing;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class AdminStockController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly AdminProductListing $listing) {}

    /** Last 10 movements of one variant. The variant is looked up through its product (another product's variant is a 404). */
    public function index(Product $product, int $variant): JsonResponse
    {
        $variant = $product->variants()->findOrFail($variant);

        return response()->json([
            'stock_quantity' => $variant->stock_quantity,
            'low_stock_threshold' => $variant->low_stock_threshold,
            'data' => $this->inventory->recentMovements($variant->id)->map(fn (InventoryMovement $movement) => $this->movement($movement))->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Sets the exact stock (the difference is what gets recorded) and answers with the refreshed product row and summary,
     * so the page does not reload. 409 when the stock is no longer the one the modal showed.
     */
    public function store(AdjustStockRequest $request, Product $product, int $variant): JsonResponse
    {
        $variant = $product->variants()->findOrFail($variant);
        $data = $request->validated();

        $movement = $this->inventory->setStock($variant->id, (int) $data['stock_quantity'], (int) $data['expected_stock'], 'Ajuste desde listado', $request->user()->id);

        return response()->json([
            'stock_quantity' => (int) $data['stock_quantity'],
            'movement' => $movement ? $this->movement($movement->load('user:id,name')) : null,
            'data' => $this->listing->find($product->id),
            'summary' => $this->inventory->summary(),
            'movements' => $this->inventory->recentMovements($variant->id)->map(fn (InventoryMovement $item) => $this->movement($item))->values(),
        ], 200)->header('Cache-Control', 'private, no-store');
    }

    private function movement(InventoryMovement $movement): array
    {
        return [
            'id' => $movement->id, 'type' => $movement->type, 'origin' => $this->inventory->origin($movement->type),
            'quantity' => $movement->quantity, 'from' => $movement->stock_after - $movement->quantity, 'stock_after' => $movement->stock_after,
            'user_name' => $movement->user?->name, 'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }
}
