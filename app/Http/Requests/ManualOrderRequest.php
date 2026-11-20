<?php

namespace App\Http\Requests;

/**
 * Validation of POST /admin/orders/manual (the administrator registers an order that came by WhatsApp or phone). Same rules as the
 * storefront (phone, department, address, cart lines, coupon) except: the email is optional, the online terms are not accepted by
 * the customer, the data authorization is what the administrator vouches for, and the shipping can be agreed by hand.
 */
class ManualOrderRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['terms_accepted']);

        return [
            ...$rules,
            'customer_email' => ['nullable', 'string', 'email', 'max:255'],
            // Null = "A coordinar" (0); a number is the shipping the administrator agreed with the customer (integer COP).
            'shipping_cop' => ['nullable', 'integer', 'min:0', 'max:99999999'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'data_consent.accepted' => 'Confirma que el cliente autorizó el tratamiento de sus datos personales (Ley 1581).',
            'data_consent.required' => 'Confirma que el cliente autorizó el tratamiento de sus datos personales (Ley 1581).',
        ];
    }

    public function attributes(): array
    {
        return [...parent::attributes(), 'shipping_cop' => 'envío', 'customer_email' => 'correo'];
    }
}
