<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * Validation of POST /api/orders: cart lines (inherited), customer, delivery and legal acceptance.
 * The storefront mirrors these rules in resources/js/modules/storefront/checkoutForm.js: keep both in sync.
 */
class StoreOrderRequest extends QuoteOrderRequest
{
    /** Keep in sync with DEPARTMENTS in resources/js/modules/storefront/checkoutForm.js. */
    public const DEPARTMENTS = [
        'Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bogotá D.C.', 'Bolívar', 'Boyacá', 'Caldas', 'Caquetá', 'Casanare',
        'Cauca', 'Cesar', 'Chocó', 'Córdoba', 'Cundinamarca', 'Guainía', 'Guaviare', 'Huila', 'La Guajira', 'Magdalena',
        'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 'Quindío', 'Risaralda', 'San Andrés y Providencia', 'Santander',
        'Sucre', 'Tolima', 'Valle del Cauca', 'Vaupés', 'Vichada',
    ];

    /** Colombian mobile: 10 digits starting with 3 (after normalization). */
    public const PHONE_REGEX = Phone::MOBILE_REGEX;

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['customer_name', 'customer_email', 'customer_phone', 'delivery_department', 'delivery_city', 'delivery_address', 'delivery_details', 'order_notes'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim($this->input($field));
            }
        }
        if (isset($clean['customer_email'])) {
            $clean['customer_email'] = mb_strtolower($clean['customer_email']);
        }
        if (isset($clean['customer_phone'])) {
            $clean['customer_phone'] = Phone::normalize($clean['customer_phone']); // only the 10 digits
        }
        foreach (['delivery_details', 'order_notes'] as $optional) {
            if (($clean[$optional] ?? null) === '') {
                $clean[$optional] = null;
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'customer_name' => ['required', 'string', 'min:3', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'regex:'.self::PHONE_REGEX],
            'delivery_department' => ['required', 'string', Rule::in(self::DEPARTMENTS)],
            'delivery_city' => ['required', 'string', 'max:255'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'delivery_details' => ['nullable', 'string', 'max:500'],
            'order_notes' => ['nullable', 'string', 'max:500'],
            'terms_accepted' => ['required', 'accepted'],
            'data_consent' => ['required', 'accepted'],
            // Optional and never pre-ticked: promotions by WhatsApp and email (Ley 1581).
            'marketing_opt_in' => ['sometimes', 'nullable', 'boolean'],
            // Total the customer reviewed; the order is refused if the database total differs.
            'expected_total_cop' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_phone.regex' => 'Ingresa un celular colombiano de 10 dígitos que empiece por 3.',
            'terms_accepted.accepted' => 'Debes aceptar los términos y condiciones.',
            'terms_accepted.required' => 'Debes aceptar los términos y condiciones.',
            'data_consent.accepted' => 'Debes autorizar el tratamiento de tus datos personales.',
            'data_consent.required' => 'Debes autorizar el tratamiento de tus datos personales.',
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'customer_name' => 'nombre completo',
            'customer_email' => 'correo electrónico',
            'customer_phone' => 'celular',
            'delivery_department' => 'departamento',
            'delivery_city' => 'ciudad',
            'delivery_address' => 'dirección',
            'delivery_details' => 'detalles de entrega',
            'order_notes' => 'notas del pedido',
            'idempotency_key' => 'clave de la solicitud',
        ];
    }
}
