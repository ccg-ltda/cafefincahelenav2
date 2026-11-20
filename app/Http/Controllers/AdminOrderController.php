<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderPresenter;
use App\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of orders. It never writes (OrderStatusService does, through AdminOrderStatusController). What each role sees is
 * decided here and in OrderPresenter, from permissions: a person without orders.view_financials only gets active, valid orders
 * and no money, coupon or payment fields; without orders.view_customer_email the search does not look at emails.
 */
class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $financial = $user->hasPermission('orders.view_financials');
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'coupon' => ['sometimes', 'nullable', 'in:with,without'],
            'channel' => ['sometimes', 'nullable', 'in:web,manual'],
        ]);

        $scope = fn () => $this->scoped(Order::query(), $user);
        $query = $scope()->select('orders.*');
        if ($financial) {
            $query->selectRaw(OrderPresenter::VERIFIED_PAYMENT.' as payment_verified, '.OrderPresenter::PAYMENT_ALERT.' as payment_alert');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $withEmail = $user->hasPermission('orders.view_customer_email');
            $query->where(function ($group) use ($search, $like, $withEmail) {
                $group->where('reference', 'like', $like)->orWhere('customer_name', 'like', $like);
                if ($withEmail) {
                    $group->orWhere('customer_email', 'like', $like); // same query: without the permission the column is never searched
                }
                if (ctype_digit($search)) {
                    $group->orWhere('orders.id', (int) $search);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['channel'])) {
            $query->where('orders.channel', $filters['channel']);
        }
        if ($financial) {
            if (! empty($filters['payment_status'])) {
                $query->where('payment_status', $filters['payment_status']);
            }
            if (($filters['coupon'] ?? null) === 'with') {
                $query->whereNotNull('orders.coupon_code');
            }
            if (($filters['coupon'] ?? null) === 'without') {
                $query->whereNull('orders.coupon_code');
            }
        }

        $page = $query->orderByDesc('orders.created_at')->orderByDesc('orders.id')->paginate(15, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($order) => $this->presenter->row($order, $user))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            // Filter options and indicators come from the stored data, never from a fixed list.
            'options' => ['statuses' => $this->statusOptions($user)]
                + ($financial ? ['payment_statuses' => Order::query()->distinct()->orderBy('payment_status')->pluck('payment_status')->values()] : []),
            'summary' => [
                'total' => $scope()->count(),
                'pending' => $scope()->where('status', 'pending')->count(),
                'completed' => $scope()->where('status', 'delivered')->count(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $user = $request->user();
        $financial = $user->hasPermission('orders.view_financials');
        $found = Order::query()->select('orders.*')
            ->when($financial, fn ($query) => $query->selectRaw(OrderPresenter::VERIFIED_PAYMENT.' as payment_verified, '.OrderPresenter::PAYMENT_ALERT.' as payment_alert'))
            ->with(['items' => fn ($query) => $query->orderBy('id'), 'history.user:id,name', 'assignedDelivery:id,name'] + ($financial ? ['payments' => fn ($query) => $query->orderBy('id')] : []))
            ->findOrFail($order);

        if ($user->hasPermission('orders.view_all')) {
            // Dispatch only works with active, valid orders: any other id answers as if it did not exist.
            abort_unless($this->scoped(Order::query(), $user)->whereKey($found->id)->exists(), 404);
        } else {
            // Delivery: only an order assigned to the person (delivered ones stay as their history); any other id is refused.
            abort_unless($user->hasPermission('orders.view_assigned') && (int) $found->assigned_delivery_user_id === (int) $user->id, 403);
        }
        if (! $financial) {
            $found->unsetRelation('payments');
        }

        return response()->json(['order' => $this->presenter->detail($found, $user)])->header('Cache-Control', 'private, no-store');
    }

    /**
     * What each role may list: admin everything; dispatch (no financials) only the active orders that count as real purchases;
     * delivery only the orders assigned to them, delivered ones included as their history.
     */
    private function scoped($query, User $user)
    {
        if ($user->hasPermission('orders.view_financials')) {
            return $query;
        }

        return $user->hasPermission('orders.view_all')
            ? $query->valid()->whereIn('orders.status', OrderStatusService::ACTIVE)
            : $query->where('orders.assigned_delivery_user_id', $user->id);
    }

    /** The states the status filter offers: the whole flow for admin, the active ones for dispatch, shipped and delivered for delivery. */
    private function statusOptions(User $user): array
    {
        $all = array_keys(OrderStatusService::LABELS);
        if ($user->hasPermission('orders.view_financials')) {
            return $all;
        }

        return $user->hasPermission('orders.view_all') ? OrderStatusService::ACTIVE : ['shipped', 'delivered'];
    }
}
