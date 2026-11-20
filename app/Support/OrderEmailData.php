<?php

namespace App\Support;

use App\Models\Order;
use App\Services\OrderStatusService;

/**
 * Everything an order email shows, as one plain array that the Blade templates (HTML and text) only print. It reads the order as
 * it is now, but the state pill and the wording follow the TYPE of the email, so a late email never says something else.
 */
final class OrderEmailData
{
    public const TYPES = [
        'customer_paid' => ['audience' => 'customer', 'state' => 'confirmed', 'label' => 'Pago confirmado'],
        'customer_preparing' => ['audience' => 'customer', 'state' => 'preparing', 'label' => 'En preparación'],
        'customer_ready' => ['audience' => 'customer', 'state' => 'ready', 'label' => 'Preparado'],
        'customer_shipped' => ['audience' => 'customer', 'state' => 'shipped', 'label' => 'En camino'],
        'customer_delivered' => ['audience' => 'customer', 'state' => 'delivered', 'label' => 'Entregado'],
        'customer_cancelled' => ['audience' => 'customer', 'state' => 'cancelled', 'label' => 'Cancelado'],
        'admin_new_paid' => ['audience' => 'admin', 'state' => 'confirmed', 'label' => 'Nuevo pedido pagado'],
        'admin_payment_on_cancelled' => ['audience' => 'admin', 'state' => 'cancelled', 'label' => 'Pago sobre pedido cancelado'],
    ];

    /** The colours of the state pill: the same tones as the admin panel (StatusBadge.css). [background, text, dark background, dark text] */
    private const TONES = [
        'confirmed' => ['#e3f1e0', '#1f4d1c', '#25422a', '#bfe6b9'],
        'preparing' => ['#e3eefc', '#1a4a8a', '#1f3552', '#bcd6f7'],
        'ready' => ['#dff1ef', '#14504a', '#1d4540', '#b5e3dd'],
        'shipped' => ['#ece6f8', '#502f8c', '#352a52', '#d3c6f1'],
        'delivered' => ['#e3f1e0', '#1f4d1c', '#25422a', '#bfe6b9'],
        'cancelled' => ['#fbe4e0', '#8c2216', '#52251f', '#f5c2ba'],
    ];

    public static function money(int $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.');
    }

    public static function subject(Order $order, string $type): string
    {
        $ref = $order->reference;

        return match ($type) {
            'customer_paid' => "Recibimos tu pago · Pedido $ref",
            'customer_preparing' => "Tu pedido $ref está en preparación",
            'customer_ready' => "Tu pedido $ref está preparado",
            'customer_shipped' => "Tu pedido $ref va en camino",
            'customer_delivered' => "Tu pedido $ref fue entregado",
            'customer_cancelled' => "Tu pedido $ref fue cancelado",
            'admin_new_paid' => "Nuevo pedido $ref · {$order->customer_name} · ".self::money((int) $order->total_cop),
            'admin_payment_on_cancelled' => "Pago recibido sobre un pedido cancelado · $ref",
        };
    }

    /** @return array<string, mixed> */
    public static function for(Order $order, string $type): array
    {
        $meta = self::TYPES[$type];
        $state = $meta['state'];
        $customer = $meta['audience'] === 'customer';
        $first = explode(' ', trim((string) $order->customer_name))[0] ?: 'cliente';
        $base = rtrim((string) config('app.url'), '/');

        $items = $order->items->map(function ($item) {
            $variant = $item->variant;
            $weight = $variant?->weight_grams && ! str_contains($item->name, $variant->weight_grams.' g') ? $variant->weight_grams.' g' : null;
            $original = $item->original_unit_price_cop ? (int) $item->original_unit_price_cop : null;

            return [
                'name' => $item->name,
                'presentation' => implode(' · ', array_filter([$weight, $variant?->grind_type])),
                'quantity' => (int) $item->quantity,
                'unit' => (int) $item->unit_price_cop,
                'original' => $original && $original > $item->unit_price_cop ? $original : null,
                'subtotal' => (int) $item->subtotal_cop,
            ];
        })->values()->all();

        // Items carry the final price; the subtotal shown is at the ORIGINAL prices so subtotal - promotions - coupon + shipping = total.
        $savings = (int) collect($items)->sum(fn ($line) => $line['original'] ? ($line['original'] - $line['unit']) * $line['quantity'] : 0);
        $coupon = (int) $order->discount_cop;

        [$title, $intro, $next] = self::copy($type, $first, $order);
        $whatsapp = (string) config('shop.whatsapp_number');

        return [
            'type' => $type, 'audience' => $meta['audience'], 'state' => $state, 'state_label' => OrderStatusService::LABELS[$state] ?? $state, 'tone' => self::TONES[$state],
            'title' => $title, 'intro' => $intro, 'next' => $next,
            'preheader' => $customer ? $intro : "{$order->reference} · {$order->customer_name} · ".self::money((int) $order->total_cop),
            'reference' => $order->reference, 'total' => self::money((int) $order->total_cop),
            'items' => $items,
            'subtotal' => self::money((int) $order->subtotal_cop + $savings),
            'savings' => $savings > 0 ? self::money($savings) : null,
            'coupon_code' => $order->coupon_code, 'coupon' => $coupon > 0 ? self::money($coupon) : null,
            'shipping' => (int) $order->shipping_cop > 0 ? self::money((int) $order->shipping_cop) : 'A coordinar',
            'delivery' => ['address' => $order->delivery_address, 'details' => $order->delivery_details, 'city' => $order->delivery_city, 'department' => $order->delivery_department],
            'notes' => $order->order_notes,
            'contact' => ['name' => $order->customer_name, 'email' => $order->customer_email ?: 'Sin correo', 'phone' => $order->customer_phone],
            'shipping_info' => $type === 'customer_shipped'
                ? array_filter(['courier' => $order->assignedDelivery?->name, 'carrier' => $order->carrier, 'tracking' => $order->tracking_number])
                : [],
            'paid_at' => $order->paid_at?->timezone('America/Bogota')->format('d/m/Y H:i'),
            'is_receipt' => $type === 'customer_paid',
            'refund' => $type === 'customer_cancelled',
            'panel_url' => $base.'/admin/orders/'.$order->id,
            'logo_url' => $base.'/media/email/logo.png',
            'store_url' => $base,
            'whatsapp_url' => $whatsapp !== '' ? 'https://wa.me/'.$whatsapp : null, 'whatsapp_number' => $whatsapp,
            'brand' => 'Café Finca Helena', 'tagline' => 'Café de Norte de Santander',
        ];
    }

    /** @return array{0: string, 1: string, 2: string} title, what happened, what comes next */
    private static function copy(string $type, string $first, Order $order): array
    {
        return match ($type) {
            'customer_paid' => ["¡Gracias, $first!", 'Recibimos tu pago y tu pedido quedó confirmado.', 'Ahora lo alistamos. Te avisaremos por correo cada vez que cambie de estado.'],
            'customer_preparing' => ['Estamos preparando tu pedido', "Hola $first, ya estamos alistando tu café.", 'Cuando esté listo te lo avisamos por aquí.'],
            'customer_ready' => ['Tu pedido está preparado', "Hola $first, tu pedido ya está empacado y listo.", 'En breve lo entregamos a quien lo llevará hasta ti.'],
            'customer_shipped' => ['Tu pedido va en camino', "Hola $first, tu pedido salió hacia tu dirección.", 'Ten a mano tu celular por si la entrega necesita coordinarse.'],
            'customer_delivered' => ['¡Entregamos tu pedido!', "Hola $first, tu pedido fue entregado.", 'Esperamos que disfrutes tu café. Si algo no llegó como esperabas, escríbenos.'],
            'customer_cancelled' => ['Tu pedido fue cancelado', "Hola $first, tu pedido fue cancelado.", 'Como tu pedido ya estaba pagado, te contactaremos para coordinar la devolución de tu dinero.'],
            'admin_new_paid' => ['Nuevo pedido pagado', "{$order->customer_name} pagó el pedido {$order->reference}.", 'Revísalo en el panel para seguir con su preparación.'],
            'admin_payment_on_cancelled' => ['Pago recibido sobre un pedido cancelado', "Wompi aprobó un pago del pedido {$order->reference}, que ya estaba cancelado. El pedido sigue cancelado.", 'Revisa el pago en el panel y coordina la devolución o la reactivación con el cliente.'],
        };
    }
}
