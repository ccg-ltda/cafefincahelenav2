<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Every promotion rule lives here: status, price calculation, validation (values, dates, overlaps) and the lookup of
 * the promotions that apply right now. OrderPricingService and the public API use it, so a price is never computed twice.
 *
 * Prices are integer COP. The promotional price is rounded to the nearest hundred and must stay between 1 and the base price - 1.
 */
class PromotionService
{
    /** The admin works in Colombian time; dates are stored in UTC. */
    public const TIMEZONE = 'America/Bogota';

    public const TYPES = ['percentage', 'fixed_amount'];

    public const MAX_PERCENTAGE = 90;

    /** current | scheduled | expired | inactive */
    public function status(Promotion $promotion, ?CarbonInterface $at = null): string
    {
        $at ??= now();
        if (! $promotion->is_active) {
            return 'inactive';
        }
        if ($promotion->starts_at && $promotion->starts_at->gt($at)) {
            return 'scheduled';
        }
        if ($promotion->ends_at && $promotion->ends_at->lte($at)) {
            return 'expired';
        }

        return 'current';
    }

    /** Base price minus the discount, rounded to the nearest hundred. */
    public function priceFor(int $basePrice, string $type, int $value): int
    {
        $raw = $type === 'percentage' ? $basePrice * (100 - $value) / 100 : $basePrice - $value;

        return (int) (round($raw / 100) * 100);
    }

    /** Why a discount cannot be applied to a variant priced `$basePrice`, or null when it is fine. */
    public function priceProblem(int $basePrice, string $type, int $value, string $variantName): ?string
    {
        $raw = $type === 'percentage' ? $basePrice * (100 - $value) / 100 : $basePrice - $value;
        $final = $this->priceFor($basePrice, $type, $value);
        if ($raw <= 0 || $final <= 0) {
            return "El descuento supera el precio de «{$variantName}».";
        }
        if ($final >= $basePrice) {
            return "El descuento es tan pequeño que el precio de «{$variantName}» no cambia.";
        }

        return null;
    }

    public function label(string $type, int $value): string
    {
        return $type === 'percentage' ? "-{$value}%" : '-$'.number_format($value, 0, ',', '.');
    }

    /**
     * The promotions that apply right now to the given variants (active, inside their dates, product and variant published).
     * If two ever overlap (they cannot be saved that way) the lowest price wins.
     *
     * @param  iterable<int>  $variantIds
     * @return array<int, array{promotion_id: int, original: int, price: int, label: string, ends_at: ?CarbonInterface}>
     */
    public function currentForVariants(iterable $variantIds, ?CarbonInterface $at = null): array
    {
        $ids = collect($variantIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $promotions = Promotion::query()->current($at)
            ->whereHas('product', fn ($product) => $product->where('is_published', true))
            ->whereHas('variants', fn ($variants) => $variants->whereIn('product_variants.id', $ids)->where('product_variants.is_published', true))
            ->with(['variants' => fn ($variants) => $variants->whereIn('product_variants.id', $ids)->where('product_variants.is_published', true)])
            ->get();

        $found = [];
        foreach ($promotions as $promotion) {
            foreach ($promotion->variants as $variant) {
                $base = (int) $variant->price_cop;
                if ($this->priceProblem($base, $promotion->discount_type, $promotion->discount_value, $variant->name) !== null) {
                    continue; // The base price changed after the promotion was saved: it no longer applies.
                }
                $price = $this->priceFor($base, $promotion->discount_type, $promotion->discount_value);
                if (! isset($found[$variant->id]) || $price < $found[$variant->id]['price']) {
                    $found[$variant->id] = [
                        'promotion_id' => $promotion->id, 'original' => $base, 'price' => $price,
                        'label' => $this->label($promotion->discount_type, $promotion->discount_value), 'ends_at' => $promotion->ends_at,
                    ];
                }
            }
        }

        return $found;
    }

    /** Loads the promotional pricing of every variant of the given products (public catalogue), in one query. */
    public function attachPricing(iterable $products): void
    {
        $variants = collect($products)->flatMap(fn (Product $product) => $product->variants);
        $pricing = $this->currentForVariants($variants->pluck('id'));
        foreach ($variants as $variant) {
            $variant->setRelation('pricing', $pricing[$variant->id] ?? null);
        }
    }

    /** Is there at least one promotion a customer could use right now? (decides whether the "Promociones" tab exists) */
    public function hasCurrent(): bool
    {
        return Promotion::query()->current()
            ->whereHas('product', fn ($product) => $product->visible())
            ->whereHas('variants', fn ($variants) => $variants->where('product_variants.is_published', true))
            ->exists();
    }

    /** Counters for the summary cards. */
    public function summary(): array
    {
        $at = now();

        return collect(['current', 'scheduled', 'expired', 'inactive'])
            ->mapWithKeys(fn (string $status) => [$status => Promotion::query()->inStatus($status, $at)->count()])->all();
    }

    /** Converts "2026-11-19T08:30" typed in Colombian time to a UTC Carbon instance. */
    public function parseLocal(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::createFromFormat('Y-m-d\TH:i', $value, self::TIMEZONE)->utc() : null;
    }

    public function formatLocal(?CarbonInterface $value): ?string
    {
        return $value?->copy()->timezone(self::TIMEZONE)->format('Y-m-d\TH:i');
    }

    /**
     * Full validation of a promotion that is about to be saved. Throws a 422 keyed by form field.
     *
     * @param  array{product_id: int, variant_ids: list<int>, discount_type: string, discount_value: int, is_active: bool, starts_at: ?Carbon, ends_at: ?Carbon}  $data
     */
    public function validateForSave(array $data, ?int $ignoreId = null): void
    {
        $errors = [];

        $product = Product::query()->find($data['product_id']);
        if (! $product || ! $product->is_published) {
            $errors['product_id'][] = 'Selecciona un producto publicado.';
        }

        $ids = array_values(array_unique(array_map('intval', $data['variant_ids'])));
        $variants = $product
            ? ProductVariant::query()->where('product_id', $product->id)->where('is_published', true)->whereIn('id', $ids)->get()
            : new Collection;
        if ($ids === [] || $variants->count() !== count($ids)) {
            $errors['variant_ids'][] = 'Selecciona al menos una presentación publicada de este producto.';
        }

        $type = $data['discount_type'];
        $value = (int) $data['discount_value'];
        if ($type === 'percentage' && ($value < 1 || $value > self::MAX_PERCENTAGE)) {
            $errors['discount_value'][] = 'El porcentaje debe estar entre 1 y '.self::MAX_PERCENTAGE.'.';
        } elseif ($value < 1) {
            $errors['discount_value'][] = 'El valor del descuento debe ser de al menos $1.';
        } else {
            foreach ($variants as $variant) {
                if ($problem = $this->priceProblem((int) $variant->price_cop, $type, $value, $variant->name)) {
                    $errors['discount_value'][] = $problem;
                }
            }
        }

        $start = $data['starts_at'];
        $end = $data['ends_at'];
        if ($start && $end && $end->lte($start)) {
            $errors['ends_at'][] = 'La fecha de fin debe ser posterior a la de inicio.';
        }

        if ($data['is_active'] && $variants->isNotEmpty() && ! isset($errors['ends_at'])) {
            foreach ($this->overlapping($variants->pluck('id')->all(), $start, $end, $ignoreId) as $other) {
                $names = $other->variants->pluck('name')->map(fn ($name) => "«{$name}»")->implode(', ');
                $errors['variant_ids'][] = "Ya existe otra promoción activa para {$names} en esas fechas ({$this->period($other)}). Cambia las fechas o las presentaciones, o desactiva la otra promoción.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Active promotions that share a variant with the given ones and whose dates cross [start, end).
     * A missing date is open-ended.
     *
     * @param  list<int>  $variantIds
     * @return Collection<int, Promotion>
     */
    public function overlapping(array $variantIds, ?CarbonInterface $start, ?CarbonInterface $end, ?int $ignoreId = null): Collection
    {
        return Promotion::query()->where('is_active', true)
            ->when($ignoreId, fn ($query) => $query->where('promotions.id', '!=', $ignoreId))
            ->whereHas('variants', fn ($variants) => $variants->whereIn('product_variants.id', $variantIds))
            ->when($end, fn ($query) => $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<', $end)))
            ->when($start, fn ($query) => $query->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start)))
            ->with(['variants' => fn ($variants) => $variants->whereIn('product_variants.id', $variantIds)])
            ->get();
    }

    /** "del 19 nov 2026 al 30 nov 2026" style text for error messages. */
    private function period(Promotion $promotion): string
    {
        $fmt = fn (?CarbonInterface $date) => $date?->copy()->timezone(self::TIMEZONE)->locale('es')->isoFormat('D MMM YYYY');

        return match (true) {
            $promotion->starts_at && $promotion->ends_at => 'del '.$fmt($promotion->starts_at).' al '.$fmt($promotion->ends_at),
            (bool) $promotion->starts_at => 'desde el '.$fmt($promotion->starts_at).', sin fecha límite',
            (bool) $promotion->ends_at => 'hasta el '.$fmt($promotion->ends_at),
            default => 'sin fechas',
        };
    }
}
