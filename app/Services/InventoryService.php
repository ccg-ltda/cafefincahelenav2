<?php

namespace App\Services;

use App\Exceptions\StockConflictException;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All stock logic lives here: adjusting stock, recording the movement and querying it.
 * `product_variants.stock_quantity` changes only through this service, always together with its
 * `inventory_movements` row, in one transaction with a row lock. Stock can never be negative.
 * The checkout will call adjust() with type 'venta' / 'devolucion' later.
 */
class InventoryService
{
    public const TYPES = ['entrada', 'salida', 'ajuste', 'venta', 'devolucion'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param  string  $type  entrada/devolucion add `$quantity`, salida/venta subtract it, ajuste sets the stock to exactly `$quantity`.
     */
    public function adjust(int $variantId, string $type, int $quantity, ?string $reason = null, ?int $userId = null, ?int $orderId = null): InventoryMovement
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Tipo de movimiento no válido.']]);
        }
        if ($quantity < ($type === 'ajuste' ? 0 : 1)) {
            throw ValidationException::withMessages(['quantity' => [$type === 'ajuste' ? 'La cantidad no puede ser negativa.' : 'La cantidad debe ser de al menos 1.']]);
        }

        return DB::transaction(function () use ($variantId, $type, $quantity, $reason, $userId, $orderId) {
            $variant = ProductVariant::query()->lockForUpdate()->findOrFail($variantId);
            $current = (int) $variant->stock_quantity;

            $delta = match ($type) {
                'entrada', 'devolucion' => $quantity,
                'salida', 'venta' => -$quantity,
                'ajuste' => $quantity - $current,
            };
            $after = $current + $delta;

            if ($after < 0) {
                throw ValidationException::withMessages(['quantity' => ["Stock insuficiente: solo hay {$current} ".($current === 1 ? 'unidad' : 'unidades').'.']]);
            }
            if ($delta === 0) {
                throw ValidationException::withMessages(['quantity' => ['La cantidad coincide con el stock actual: no hay nada que ajustar.']]);
            }

            $variant->forceFill(['stock_quantity' => $after])->save();

            return InventoryMovement::create([
                'product_variant_id' => $variant->id, 'type' => $type, 'quantity' => $delta, 'stock_after' => $after,
                'reason' => filled($reason) ? trim($reason) : null, 'user_id' => $userId, 'order_id' => $orderId,
            ]);
        });
    }

    /**
     * Sets the stock to exactly `$quantity` (product editor and stock modal), as an 'ajuste' movement with the difference.
     * `$reason` is an automatic text ("Edición de producto", "Ajuste desde listado").
     * `$expectedStock` is the stock the form had when it was opened: if the customer-facing side changed it meanwhile
     * (a sale, another admin) nothing is overwritten and a StockConflictException (HTTP 409) is thrown.
     * Returns null, without touching anything, when the value did not change.
     */
    public function setStock(int $variantId, int $quantity, int $expectedStock, string $reason, ?int $userId = null): ?InventoryMovement
    {
        if ($quantity === $expectedStock) {
            return null;
        }

        return DB::transaction(function () use ($variantId, $quantity, $expectedStock, $reason, $userId) {
            $current = (int) ProductVariant::query()->lockForUpdate()->findOrFail($variantId)->stock_quantity;
            if ($current !== $expectedStock) {
                throw new StockConflictException($current);
            }

            $movement = $this->adjust($variantId, 'ajuste', $quantity, $reason, $userId);
            // Audit trail: the administrator's stock correction (sales and returns are not "stock_adjusted").
            $variant = ProductVariant::query()->with('product')->findOrFail($variantId);
            $this->activity->log('inventory', 'stock_adjusted', $variant, ['stock_quantity' => [$expectedStock, $quantity]], ['reason' => $reason, 'sku' => $variant->sku]);

            return $movement;
        });
    }

    /**
     * Takes the units of an order out of stock ("venta" movements linked to the order). Call it inside the transaction
     * that creates the order: every variant row is locked first (in id order, so two orders cannot deadlock) and, if any
     * line does not fit, nothing is touched and a 422 names the product and how many are left.
     *
     * @param  array<int, int>  $quantities  variant id => units
     */
    public function sellOrder(Order $order, array $quantities): void
    {
        ksort($quantities);

        DB::transaction(function () use ($order, $quantities) {
            $variants = ProductVariant::query()->whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $short = [];
            foreach ($quantities as $id => $units) {
                $variant = $variants->get($id);
                $left = (int) ($variant?->stock_quantity ?? 0);
                if ($left < $units) {
                    $short[] = $left <= 0 ? "«{$variant?->name}» está agotado." : "Solo quedan {$left} de «{$variant->name}».";
                }
            }
            if ($short !== []) {
                throw ValidationException::withMessages(['items' => $short]);
            }

            foreach ($quantities as $id => $units) {
                $this->adjust($id, 'venta', $units, "Venta {$order->reference}", null, $order->id);
            }
        });
    }

    /**
     * Puts back the stock of a whole order ("devolucion" movements), for when an administrator cancels it.
     * It returns exactly what that order sold (its "venta" movements) and only once; orders that never took stock
     * out (created before inventory existed) return nothing. Returns the number of units put back.
     */
    public function returnOrder(Order $order, ?int $userId = null): int
    {
        return DB::transaction(function () use ($order, $userId) {
            Order::query()->lockForUpdate()->findOrFail($order->id); // serializes two returns of the same order
            $movements = InventoryMovement::query()->where('order_id', $order->id);
            if ((clone $movements)->where('type', 'devolucion')->exists()) {
                return 0;
            }

            $sold = (clone $movements)->where('type', 'venta')->get()->groupBy('product_variant_id')->map(fn ($rows) => -$rows->sum('quantity'));
            $units = 0;
            foreach ($sold as $variantId => $quantity) {
                if ($quantity > 0) {
                    $this->adjust((int) $variantId, 'devolucion', (int) $quantity, "Devolución {$order->reference}", $userId, $order->id);
                    $units += $quantity;
                }
            }

            if ($units > 0 && $userId !== null) {
                $this->activity->log('inventory', 'stock_returned', $order, [], ['units' => $units, 'reason' => "Devolución {$order->reference}"]);
            }

            return $units;
        });
    }

    /** Where a movement came from, for the history shown to administrators. */
    public function origin(string $type): string
    {
        return match ($type) {
            'venta' => 'Venta',
            'devolucion' => 'Devolución',
            default => 'Admin',
        };
    }

    /** Stock of a variant that has just been created (call it inside the creating transaction). */
    public function initialStock(ProductVariant $variant, int $quantity, ?int $userId = null): void
    {
        if ($quantity > 0) {
            $this->adjust($variant->id, 'entrada', $quantity, 'Stock inicial', $userId);
        }
    }

    /** @return Collection<int, InventoryMovement> */
    public function recentMovements(int $variantId, int $limit = 10): Collection
    {
        return InventoryMovement::query()->with('user:id,name')->where('product_variant_id', $variantId)
            ->orderByDesc('id')->limit($limit)->get();
    }

    /** 'out' = no units, 'low' = 1..threshold units, 'ok' otherwise. */
    public function state(int $stock, int $threshold): string
    {
        return $stock <= 0 ? 'out' : ($stock <= $threshold ? 'low' : 'ok');
    }

    /**
     * Stock state of a product from its loaded variants. Only published variants count:
     * 'none' = no published variants, 'out' = all of them out, 'low' = at least one low, else 'ok'.
     *
     * @param  iterable<ProductVariant>  $variants
     */
    public function productState(iterable $variants): string
    {
        $states = [];
        foreach ($variants as $variant) {
            if ($variant->is_published) {
                $states[] = $this->state((int) $variant->stock_quantity, (int) $variant->low_stock_threshold);
            }
        }
        if ($states === []) {
            return 'none';
        }
        if (! array_diff($states, ['out'])) {
            return 'out';
        }

        return in_array('low', $states, true) ? 'low' : 'ok';
    }

    /** Restricts a Product query to the products whose stock state is 'low' or 'out' (published variants only). */
    public function filterProducts(Builder $products, string $state): Builder
    {
        $published = fn (Builder $variants) => $variants->where('is_published', true);

        return match ($state) {
            'low' => $products->whereHas('variants', fn (Builder $variants) => $published($variants)
                ->where('stock_quantity', '>', 0)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')),
            'out' => $products->whereHas('variants', $published)
                ->whereDoesntHave('variants', fn (Builder $variants) => $published($variants)->where('stock_quantity', '>', 0)),
            default => $products,
        };
    }

    /** Counters for the summary cards (whole catalogue, independent of the list filters). */
    public function summary(): array
    {
        return [
            'total' => Product::query()->count(),
            'active' => Product::query()->where('is_published', true)->count(),
            'low' => $this->filterProducts(Product::query(), 'low')->count(),
            'out' => $this->filterProducts(Product::query(), 'out')->count(),
        ];
    }
}
