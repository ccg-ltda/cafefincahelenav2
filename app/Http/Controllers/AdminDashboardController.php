<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['sometimes', 'required', Rule::in(['7', '30', '90'])]]);
        $days = (int) ($validated['days'] ?? 30);
        $now = Carbon::now('America/Bogota');
        $start = $now->copy()->startOfDay()->subDays($days - 1)->utc();
        $end = $now->copy()->utc();

        // Collapse verified attempts before joining orders, so one order contributes once.
        $confirmed = DB::table('payments')->join('orders as payment_orders', 'payment_orders.id', '=', 'payments.order_id')
            ->where('payments.status', 'approved')->whereNotNull('payments.verified_at')
            ->whereNotNull('payments.confirmed_at')->whereNotNull('payments.external_transaction_id')->where('payments.external_transaction_id', '<>', '')
            ->where('payments.currency', 'COP')->where('payment_orders.currency', 'COP')
            ->whereColumn('payments.amount_cop', 'payment_orders.total_cop')
            ->groupBy('payments.order_id')
            ->select('payments.order_id')->selectRaw('MIN(payments.confirmed_at) as confirmed_at');

        $paid = DB::table('orders')->joinSub($confirmed, 'confirmed', 'confirmed.order_id', '=', 'orders.id')
            ->where('confirmed.confirmed_at', '>=', $start)->where('confirmed.confirmed_at', '<=', $end);
        $orders = (clone $paid)->select('orders.id', 'orders.total_cop', 'orders.status', 'orders.delivery_city', 'confirmed.confirmed_at')->get();
        $sales = (int) $orders->sum('total_cop');
        $count = $orders->count();
        $byDay = $orders->groupBy(fn ($order) => Carbon::parse($order->confirmed_at, 'UTC')->setTimezone('America/Bogota')->toDateString())
            ->map(fn ($group, $date) => ['label' => $date, 'value' => (int) $group->sum('total_cop')])->sortKeys()->values();
        if ($count) {
            $dailyTotals = $byDay->keyBy('label');
            $byDay = collect();
            $day = $start->copy()->setTimezone('America/Bogota')->startOfDay();
            for ($i = 0; $i < $days; $i++, $day->addDay()) {
                $date = $day->toDateString();
                $byDay->push($dailyTotals->get($date, ['label' => $date, 'value' => 0]));
            }
        }
        $byStatus = $orders->groupBy('status')->map(fn ($group, $status) => ['label' => $status, 'value' => $group->count()])->values();
        $byCity = $orders->groupBy(fn ($order) => trim($order->delivery_city))->map(fn ($group, $city) => ['label' => $city, 'value' => (int) $group->sum('total_cop')])->sortByDesc('value')->take(5)->values();
        $products = DB::table('order_items')->joinSub($paid->select('orders.id'), 'paid_orders', 'paid_orders.id', '=', 'order_items.order_id')
            ->select('order_items.sku', 'order_items.name')->selectRaw('SUM(order_items.quantity) as value')
            ->groupBy('order_items.sku', 'order_items.name')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($row) => ['label' => $row->name.' ('.$row->sku.')', 'value' => (int) $row->value]);
        $wompi = (clone $paid)->whereExists(function ($query) {
            $query->selectRaw('1')->from('payments')->whereColumn('payments.order_id', 'orders.id')
                ->where('payments.provider', 'wompi')->where('payments.status', 'approved')
                ->whereNotNull('payments.verified_at')->whereNotNull('payments.confirmed_at')
                ->whereNotNull('payments.external_transaction_id')->where('payments.external_transaction_id', '<>', '')->where('payments.currency', 'COP')
                ->whereColumn('payments.amount_cop', 'orders.total_cop');
        })->count();

        // A guest is identified by trimmed, case-insensitive email; first order, regardless of payment.
        $firstOrders = DB::table('orders')->selectRaw('LOWER(TRIM(customer_email)) as email, MIN(created_at) as first_order')
            ->whereRaw("TRIM(customer_email) <> ''")->groupByRaw('LOWER(TRIM(customer_email))');
        $newCustomers = DB::query()->fromSub($firstOrders, 'guests')->where('first_order', '>=', $start)->where('first_order', '<=', $end)->count();
        $recent = DB::table('orders')->leftJoinSub($confirmed, 'confirmed', 'confirmed.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', $start)->where('orders.created_at', '<=', $end)
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id')->limit(5)
            ->get(['orders.reference', 'orders.customer_name', 'orders.total_cop', 'orders.payment_status', 'orders.status', 'confirmed.confirmed_at'])
            ->map(function ($order) {
                if ($order->confirmed_at) $order->payment_status = 'paid';
                elseif (in_array($order->payment_status, ['paid', 'approved'], true)) $order->payment_status = 'unverified';
                unset($order->confirmed_at);
                return $order;
            });
        $tasks = DB::table('orders')->whereIn('status', ['pending', 'preparing'])->select('status')
            ->selectRaw('COUNT(*) as value')->groupBy('status')->get()
            ->map(fn ($row) => ['label' => $row->status, 'value' => (int) $row->value]);

        return response()->json([
            'days' => $days, 'from' => $start->toIso8601String(), 'to' => $end->toIso8601String(),
            'metrics' => ['paid_sales_cop' => $sales, 'paid_orders' => $count, 'average_ticket_cop' => $count ? intdiv($sales, $count) + (($sales % $count) >= intdiv($count, 2) + ($count % 2) ? 1 : 0) : 0, 'new_customers' => $newCustomers],
            'sales_by_day' => $byDay, 'orders_by_status' => $byStatus,
            'wompi_payments' => $wompi ? [['label' => 'Aprobados y verificados', 'value' => $wompi]] : [],
            'top_products' => $products, 'sales_by_city' => $byCity, 'recent_orders' => $recent, 'tasks' => $tasks,
        ])->header('Cache-Control', 'private, no-store');
    }
}
