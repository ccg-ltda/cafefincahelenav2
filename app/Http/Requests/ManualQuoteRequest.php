<?php

namespace App\Http\Requests;

/** The summary of a manual order while it is being typed: the storefront's cart lines plus the shipping agreed by hand. */
class ManualQuoteRequest extends QuoteOrderRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'shipping_cop' => ['nullable', 'integer', 'min:0', 'max:99999999']];
    }
}
