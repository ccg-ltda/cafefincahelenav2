<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\CustomerListing;
use App\Services\CustomerService;
use App\Support\ActivityRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin-only customer module. Every endpoint sits behind EnsureAdministrator; nothing here is public. */
class AdminCustomerController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly CustomerListing $listing, private readonly CustomerService $customers, private readonly ActivityLogger $activity) {}

    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter' => ['sometimes', 'nullable', Rule::in(CustomerListing::FILTERS)],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(CustomerListing::SORTS))],
            'dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    private function filtered(array $filters)
    {
        $query = $this->listing->filter($this->listing->base(), $filters['filter'] ?? null);

        return $this->listing->sort($this->listing->search($query, $filters['search'] ?? null), $filters['sort'] ?? null, $filters['dir'] ?? null);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->filtered($filters)->paginate(self::PER_PAGE, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Customer $customer) => $this->row($customer))->values(),
            'counters' => $this->listing->counters(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(int $customer): JsonResponse
    {
        $found = $this->listing->base()->where('customers.id', $customer)->firstOrFail();

        return response()->json(['data' => $this->detail($found)])->header('Cache-Control', 'private, no-store');
    }

    /** "The customer asked not to receive promotions." There is no undo: only a new purchase with the box ticked re-activates it. */
    public function optOut(int $customer): JsonResponse
    {
        $found = $this->listing->base()->where('customers.id', $customer)->firstOrFail();
        $this->customers->optOut($found);

        return response()->json(['data' => $this->detail($this->listing->base()->where('customers.id', $customer)->firstOrFail())])->header('Cache-Control', 'private, no-store');
    }

    /** CSV of the current filter: UTF-8 with BOM and ";" so Excel shows accents and columns correctly. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);
        $rows = (clone $query)->count();
        Log::info('customers.export', ['user_id' => $request->user()->id, 'filter' => $filters['filter'] ?? null, 'search' => $filters['search'] ?? null, 'rows' => $rows, 'at' => now()->toIso8601String()]);
        $search = $filters['search'] ?? null;
        $this->activity->log('customers', 'exported', null, [], array_filter([
            'filter' => $filters['filter'] ?? null, 'search' => $search ? (ActivityRedactor::maskEmail($search) ?? $search) : null, 'rows' => $rows,
        ], fn ($value) => $value !== null), 'Clientes: exportación CSV');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Email', 'Celular', 'Ciudad', 'Departamento', 'Pedidos', 'Gastado', 'Primera compra', 'Última compra', 'Acepta promociones'], ';');
            foreach ($query->cursor() as $customer) {
                fputcsv($out, array_map([$this, 'safeCell'], [
                    $customer->name, $customer->email, $customer->phone, $customer->city, $customer->department, (int) $customer->orders_count, (int) $customer->spent_cop,
                    $this->listing->iso($customer->first_order_at), $this->listing->iso($customer->last_order_at), $customer->acceptsMarketing() ? 'sí' : 'no',
                ]), ';');
            }
            fclose($out);
        }, 'clientes-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Text typed by the public (names, cities) must not run as a formula when the CSV is opened in Excel. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function row(Customer $customer): array
    {
        return [
            'id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'city' => $customer->city, 'department' => $customer->department,
            'orders_count' => (int) $customer->orders_count, 'spent_cop' => (int) $customer->spent_cop,
            'first_order_at' => $this->listing->iso($customer->first_order_at), 'last_order_at' => $this->listing->iso($customer->last_order_at),
            'accepts_marketing' => $customer->acceptsMarketing(),
        ];
    }

    private function detail(Customer $customer): array
    {
        $orders = $customer->orders()->orderByDesc('created_at')->orderByDesc('id')->get();
        $validIds = $customer->orders()->valid()->pluck('orders.id')->all();
        $count = (int) $customer->orders_count;
        $spent = (int) $customer->spent_cop;
        $topProducts = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $validIds)->groupBy('order_items.name')->orderByDesc('units')->orderBy('order_items.name')->limit(5)
            ->selectRaw('order_items.name, sum(order_items.quantity) as units')->get()
            ->map(fn ($row) => ['name' => $row->name, 'units' => (int) $row->units])->all();

        return $this->row($customer) + [
            'delivery_department' => $customer->department, 'last_address' => $customer->last_address, 'last_address_details' => $customer->last_address_details,
            'consents' => [
                'data_consent_at' => $this->listing->iso($customer->orders()->min('data_consent_at')),
                'marketing_opt_in_at' => $customer->marketing_opt_in_at?->toIso8601String(), 'marketing_opt_out_at' => $customer->marketing_opt_out_at?->toIso8601String(),
            ],
            'metrics' => ['orders' => $count, 'spent_cop' => $spent, 'average_ticket_cop' => $count ? (int) round($spent / $count) : 0],
            'top_products' => $topProducts,
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id, 'reference' => $order->reference, 'created_at' => $order->created_at?->toIso8601String(), 'total_cop' => $order->total_cop,
                'status' => $order->status, 'payment_status' => $order->payment_status, 'coupon_code' => $order->coupon_code, 'counts' => in_array($order->id, $validIds, true),
            ])->values(),
        ];
    }
}
