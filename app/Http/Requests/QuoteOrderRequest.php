<?php

namespace App\Http\Requests;

use App\Services\OrderPricingService;
use Illuminate\Foundation\Http\FormRequest;

/** Cart lines shared by the quote and the order creation. */
class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderPricingService::MAX_QUANTITY],
            // Optional coupon; its rules live in CouponService. The email lets the per-customer limit be checked in the quote.
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'customer_email' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items' => 'carrito',
            'items.*.variant_id' => 'producto',
            'items.*.quantity' => 'cantidad',
            'coupon_code' => 'cupón',
        ];
    }
}
