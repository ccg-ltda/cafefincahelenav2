<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\OrderEmailData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Looks at the email templates in the browser, with a real order or with sample data. It NEVER sends anything and has no
 * side effects. Admin only (emails.manage); the pages are not indexable and not cached.
 */
class AdminEmailPreviewController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()->orderByDesc('id')->limit(25)->get(['id', 'reference', 'customer_name', 'status']);

        return $this->plain(response()->view('emails.preview-index', ['types' => OrderEmailData::TYPES, 'orders' => $orders, 'selected' => (string) $request->query('order', 'example')]));
    }

    public function show(Request $request, string $type): Response
    {
        abort_unless(isset(OrderEmailData::TYPES[$type]), 404);
        $choice = (string) $request->query('order', 'example');
        $order = $choice === 'example' ? $this->example($type) : Order::query()->with(['items.variant', 'assignedDelivery'])->findOrFail((int) $choice);
        $data = OrderEmailData::for($order, $type);

        if ($request->query('format') === 'text') {
            return $this->plain(response(view('emails.order-text', ['d' => $data])->render(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        return $this->plain(response()->view('emails.order', ['d' => $data]));
    }

    private function plain(Response $response): Response
    {
        return $response->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** An order that exists only in memory (never saved): enough to draw every template, with a promotion and a coupon. */
    private function example(string $type): Order
    {
        $order = new Order([
            'reference' => 'CFH-EJEMPLO00001', 'customer_name' => 'María González', 'customer_email' => 'maria@example.com', 'customer_phone' => '3001234567',
            'delivery_department' => 'Cundinamarca', 'delivery_city' => 'Bogotá', 'delivery_address' => 'Calle 45 # 12-34', 'delivery_details' => 'Apto 301, torre 2',
            'order_notes' => 'Por favor llamar al llegar.', 'subtotal_cop' => 99000, 'shipping_cop' => 0, 'discount_cop' => 9900, 'total_cop' => 89100,
            'coupon_code' => 'HELENA10', 'payment_status' => 'paid', 'carrier' => 'Servientrega', 'tracking_number' => '2025-00098123',
        ]);
        $order->id = 1234;
        $order->paid_at = now();
        $order->setRelation('items', collect([
            new OrderItem(['name' => 'Café Premium 250 g · Molido', 'sku' => 'FH-001', 'quantity' => 2, 'unit_price_cop' => 36000, 'original_unit_price_cop' => 40000, 'subtotal_cop' => 72000]),
            new OrderItem(['name' => 'Café de Origen 125 g · En grano', 'sku' => 'FH-002', 'quantity' => 1, 'unit_price_cop' => 27000, 'subtotal_cop' => 27000]),
        ]));
        $order->setRelation('assignedDelivery', null);

        return $order;
    }
}
