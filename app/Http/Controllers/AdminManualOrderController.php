<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderNotPayableException;
use App\Exceptions\PaymentLinkException;
use App\Exceptions\WompiRedirectUrlException;
use App\Http\Requests\ManualOrderRequest;
use App\Http\Requests\ManualQuoteRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Services\ActivityLogger;
use App\Services\OrderCreationService;
use App\Services\OrderPricingService;
use App\Services\PaymentService;
use App\Services\PromotionService;
use App\Services\WompiService;
use App\Support\Phone;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Pedido manual" (/admin/orders/new): the administrator registers an order that came by WhatsApp or phone. Everything goes through the
 * same services as the storefront (OrderPricingService, OrderCreationService, PaymentService); the only way to pay is a Wompi link.
 * No endpoint here can mark an order as paid: only PaymentService does, when Wompi approves.
 */
class AdminManualOrderController extends Controller
{
    private const SEARCH_LIMIT = 60;

    public function __construct(
        private readonly OrderPricingService $pricing,
        private readonly OrderCreationService $orders,
        private readonly PaymentService $payments,
        private readonly PromotionService $promotions,
        private readonly WompiService $wompi,
        private readonly ActivityLogger $activity,
    ) {}

    /** Published variants to pick from: name, presentation, price (promotional when one applies) and stock. Sold-out ones are listed too. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:80']]);
        $search = trim((string) ($data['search'] ?? ''));

        $variants = ProductVariant::query()->where('product_variants.is_published', true)
            ->whereHas('product', fn ($product) => $product->visible())
            ->with('product:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn ($group) => $group->where('product_variants.name', 'like', $like)->orWhere('product_variants.sku', 'like', $like)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $like)));
            })
            ->orderBy('product_variants.name')->orderBy('product_variants.id')->limit(self::SEARCH_LIMIT)->get();

        $pricing = $this->promotions->currentForVariants($variants->pluck('id'));

        return $this->json(['data' => $variants->map(function (ProductVariant $variant) use ($pricing) {
            $promo = $pricing[$variant->id] ?? null;

            return [
                'id' => $variant->id, 'sku' => $variant->sku, 'product_name' => $variant->product?->name, 'name' => $variant->name,
                'weight_grams' => $variant->weight_grams, 'grind_type' => $variant->grind_type,
                'price_cop' => $promo['price'] ?? (int) $variant->price_cop,
                'original_price_cop' => $promo ? $promo['original'] : null, 'discount_label' => $promo['label'] ?? null,
                'available_quantity' => (int) $variant->stock_quantity,
                'image_url' => $variant->image_path ? Storage::disk('public')->url($variant->image_path) : null,
            ];
        })->values()]);
    }

    /** Looks for an existing customer by phone or email so the form can offer "Usar datos de …". Exact match only. */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['sometimes', 'nullable', 'string', 'max:30'], 'email' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $phone = filled($data['phone'] ?? null) ? Phone::normalize($data['phone']) : null;

        $customer = null;
        $by = null;
        if ($email) {
            $customer = Customer::query()->where('email', $email)->first();
            $by = $customer ? 'email' : null;
        }
        if (! $customer && $phone && preg_match(Phone::MOBILE_REGEX, $phone)) {
            $customer = Customer::query()->where('phone', $phone)->orderByDesc('updated_at')->orderByDesc('id')->first();
            $by = $customer ? 'phone' : null;
        }

        return $this->json(['customer' => $customer ? [
            'id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'city' => $customer->city,
            'department' => $customer->department, 'last_address' => $customer->last_address, 'last_address_details' => $customer->last_address_details,
        ] : null, 'matched_by' => $by]);
    }

    /** The summary, from the same OrderPricingService as the storefront (never calculated in the browser). */
    public function quote(ManualQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->json(['data' => $this->pricing->quote($data['items'], $data['coupon_code'] ?? null, $data['customer_email'] ?? null, isset($data['shipping_cop']) ? (int) $data['shipping_cop'] : null)]);
    }

    /** Creates the order (pending, channel manual) and its first payment link in ONE transaction. */
    public function store(ManualOrderRequest $request): JsonResponse
    {
        if ($refusal = $this->paymentsUnavailable()) {
            return $refusal;
        }
        $data = $request->validated();
        $user = $request->user();
        $reference = 'CFH-'.strtoupper(substr(hash('sha256', $data['idempotency_key']), 0, 12));

        if ($existing = Order::where('reference', $reference)->first()) {
            return $this->replay($existing, $user->id);
        }

        try {
            [$order, $payment] = DB::transaction(function () use ($data, $reference, $user) {
                $order = $this->orders->create($data, $reference, [
                    'channel' => 'manual', 'created_by' => $user->id, 'terms_accepted' => false,
                    'shipping_cop' => isset($data['shipping_cop']) ? (int) $data['shipping_cop'] : null,
                    'payment_due_at' => now()->addHours((int) config('services.wompi.manual_pending_expiry_hours', 24)),
                ]);
                $payment = $this->payments->startAttempt($order);

                $changes = isset($data['shipping_cop']) ? ['shipping_cop' => ['A coordinar', (int) $data['shipping_cop']]] : [];
                $this->activity->log('orders', 'created_manual', $order, $changes, ['channel' => 'manual', 'attempt' => $payment->reference, 'expires' => $payment->checkout_expires_at?->toIso8601String()], 'Pedido manual '.$order->reference, $user);

                return [$order->refresh(), $payment];
            });
        } catch (QueryException $error) {
            // A double click: the same key created the order first.
            if ((string) $error->getCode() === '23000' && ($existing = Order::where('reference', $reference)->first())) {
                return $this->replay($existing, $user->id);
            }
            throw $error;
        }

        return $this->confirmation($order, $payment, 201);
    }

    /** The link that can still be paid (to copy it again). */
    public function link(Request $request, int $order): JsonResponse
    {
        $found = Order::query()->findOrFail($order);
        if ($found->channel !== 'manual') {
            return $this->json(['message' => 'Solo los pedidos manuales tienen un link de pago generado por el equipo.'], 422);
        }
        if ($refusal = $this->paymentsUnavailable()) {
            return $refusal;
        }
        $payment = $found->status === 'pending' && $found->payment_status === 'pending' ? $this->payments->openAttempt($found) : null;
        if (! $payment) {
            return $this->json(['message' => 'No hay un link de pago vigente para este pedido.'], 404);
        }

        return $this->confirmation($found, $payment, 200);
    }

    /** A new link for the SAME order, only when the current one has expired. */
    public function renewLink(Request $request, int $order): JsonResponse
    {
        $found = Order::query()->findOrFail($order);
        if ($refusal = $this->paymentsUnavailable()) {
            return $refusal;
        }
        try {
            $payment = $this->payments->renewManualLink($found, $request->user());
        } catch (PaymentLinkException $error) {
            return $this->json(['message' => $error->getMessage()], $error->status);
        } catch (OrderNotPayableException $error) {
            return $this->json(['message' => $error->getMessage(), 'paid' => $error->alreadyPaid], 409);
        }

        return $this->confirmation($found->refresh(), $payment, 201);
    }

    /** Wompi must be configured and the return URL public: otherwise no order is created (nothing could be charged). */
    private function paymentsUnavailable(): ?JsonResponse
    {
        if (! $this->wompi->enabled()) {
            return $this->json(['message' => 'Los pagos en línea no están disponibles (faltan las llaves de Wompi). No se puede generar el link de pago.'], 503);
        }
        try {
            $this->wompi->redirectUrl();
        } catch (WompiRedirectUrlException $error) {
            return $this->json(['message' => $error->getMessage(), 'code' => WompiRedirectUrlException::CODE], 503);
        }

        return null;
    }

    /** The same key again (double click or retry): the order that already exists, with its link when it still has one. */
    private function replay(Order $existing, int $userId): JsonResponse
    {
        if ($existing->channel !== 'manual' || (int) $existing->created_by !== $userId) {
            return $this->json(['message' => 'Esta solicitud ya fue procesada con otros datos. Recarga la página e inténtalo de nuevo.'], 409);
        }
        $payment = $existing->status === 'pending' && $existing->payment_status === 'pending' ? $this->payments->openAttempt($existing) : null;

        return $payment ? $this->confirmation($existing, $payment, 200) : $this->json(['order' => $this->orderData($existing), 'payment' => null], 200);
    }

    /** What the confirmation screen shows: reference, total, the payment link, the deadline and the WhatsApp message for the customer. */
    private function confirmation(Order $order, Payment $payment, int $status): JsonResponse
    {
        $url = $this->wompi->checkoutUrl($payment, $order);
        $expires = $payment->checkout_expires_at;
        $when = $expires->copy()->timezone('America/Bogota')->locale('es')->isoFormat('D [de] MMMM [de] YYYY, h:mm a');
        $message = 'Hola '.Str::title(mb_strtolower($order->customer_name)).', te escribimos de Café Finca Helena. Este es el link para pagar tu pedido '
            .$order->reference.' por $'.number_format($order->total_cop, 0, ',', '.').': '.$url.'. Tienes hasta '.$when.' para pagarlo.';

        return $this->json([
            'order' => $this->orderData($order),
            'payment' => ['url' => $url, 'reference' => $payment->reference, 'expires_at' => $expires->toIso8601String(), 'expires_label' => $when],
            'whatsapp' => ['message' => $message, 'url' => 'https://wa.me/57'.$order->customer_phone.'?text='.rawurlencode($message)],
        ], $status);
    }

    private function orderData(Order $order): array
    {
        return ['id' => $order->id, 'reference' => $order->reference, 'total_cop' => $order->total_cop, 'shipping_cop' => $order->shipping_cop,
            'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone, 'status' => $order->status, 'payment_status' => $order->payment_status];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
