<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PendingOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public doors for a customer who comes back to the store with a pending order (reference + email prove it is theirs). A reference
 * that does not exist and an email that does not match answer exactly the same, so nobody can find out which orders exist.
 */
class PendingOrderController extends Controller
{
    private const UNKNOWN = ['state' => 'unknown'];

    public function __construct(private readonly PendingOrderService $pending) {}

    public function status(Request $request): JsonResponse
    {
        $order = $this->find($request);

        return $this->json($order ? $this->pending->state($order) : self::UNKNOWN);
    }

    public function cancel(Request $request): JsonResponse
    {
        $order = $this->find($request);
        if (! $order) {
            return $this->json(['message' => 'No encontramos ese pedido o ya no se puede cancelar.'], 404);
        }

        return match ($this->pending->cancel($order)) {
            'cancelled' => $this->json(['state' => 'cancelled']),
            'paid' => $this->json(['state' => 'paid', 'message' => 'Tu pedido ya está pagado: no se puede cancelar.'], 409),
            'in_flight' => $this->json(['state' => 'pending', 'message' => 'Tu pago está en proceso en Wompi. No podemos cancelar el pedido hasta que se resuelva.'], 409),
            default => $this->json(['message' => 'No pudimos comprobar tu pago en este momento. Inténtalo de nuevo en un momento.'], 503),
        };
    }

    /** Both fields must be plain strings (an array used to end in a 500); anything else is a 422 that says nothing about any order. */
    private function find(Request $request): ?Order
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:40'], 'customer_email' => ['required', 'string', 'max:255']]);

        return $this->pending->find($data['reference'], $data['customer_email']);
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
