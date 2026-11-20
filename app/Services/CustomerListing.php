<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers with their figures, computed from `orders` on every query (never stored). A customer is listed only when
 * it has at least one valid order (Order::scopeValid).
 */
class CustomerListing
{
    public const FILTERS = ['recurring', 'recover', 'new', 'promos'];

    public const SORTS = ['spent' => 's.spent_cop', 'orders' => 's.orders_count', 'last' => 's.last_order_at'];

    public const RECOVER_DAYS = 45;

    public const NEW_DAYS = 30;

    /** Customers joined with their valid-order figures: orders_count, spent_cop, first_order_at, last_order_at. */
    public function base(): Builder
    {
        $figures = Order::query()->valid()->whereNotNull('orders.customer_id')->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id, count(*) as orders_count, sum(orders.total_cop) as spent_cop, min(orders.created_at) as first_order_at, max(orders.created_at) as last_order_at');

        return Customer::query()->joinSub($figures, 's', 's.customer_id', '=', 'customers.id')
            ->select('customers.*', 's.orders_count', 's.spent_cop', 's.first_order_at', 's.last_order_at');
    }

    public function filter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'recurring' => $query->where('s.orders_count', '>=', 2),
            'recover' => $query->where('s.last_order_at', '<', now()->subDays(self::RECOVER_DAYS)),
            'new' => $query->where('s.first_order_at', '>=', now()->subDays(self::NEW_DAYS)),
            'promos' => $query->whereNotNull('customers.marketing_opt_in_at')
                ->where(fn (Builder $q) => $q->whereNull('customers.marketing_opt_out_at')->orWhereColumn('customers.marketing_opt_in_at', '>', 'customers.marketing_opt_out_at')),
            default => $query,
        };
    }

    public function search(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '\\%_').'%';

        return $query->where(fn (Builder $q) => $q->where('customers.name', 'like', $like)->orWhere('customers.email', 'like', $like)
            ->orWhere('customers.city', 'like', $like)->orWhere('customers.phone', 'like', $like));
    }

    public function sort(Builder $query, ?string $sort, ?string $direction): Builder
    {
        $column = self::SORTS[$sort] ?? self::SORTS['last'];

        return $query->orderBy($column, $direction === 'asc' ? 'asc' : 'desc')->orderByDesc('customers.id');
    }

    /** Global counters of the filter pills (they ignore the search and the active pill). */
    public function counters(): array
    {
        $counters = ['all' => $this->base()->count()];
        foreach (self::FILTERS as $filter) {
            $counters[$filter] = $this->filter($this->base(), $filter)->count();
        }

        return $counters;
    }

    /** Figures are UTC strings from the query; the API gives ISO 8601. */
    public function iso(?string $value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}
