<?php

namespace App\Http\Requests;

use App\Services\CouponService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a coupon form. Business rules (code format and uniqueness, percentage range, dates) are in CouponService::validateForSave.
 * `starts_at` / `ends_at` arrive as "YYYY-MM-DDTHH:mm" in Colombian time.
 */
class SaveCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route group already requires an administrator.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
        foreach (['description', 'max_discount_cop', 'max_uses', 'starts_at', 'ends_at'] as $optional) {
            if ($this->input($optional) === '') {
                $this->merge([$optional => null]);
            }
        }
        if ($this->input('min_subtotal_cop') === '' || $this->input('min_subtotal_cop') === null) {
            $this->merge(['min_subtotal_cop' => 0]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in(CouponService::TYPES)],
            'discount_value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'max_discount_cop' => ['nullable', 'integer', 'min:100', 'max:100000000'],
            'min_subtotal_cop' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'exclude_promoted_items' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'starts_at.date_format' => 'La fecha de inicio no es válida.',
            'ends_at.date_format' => 'La fecha de fin no es válida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'código', 'description' => 'descripción', 'discount_type' => 'tipo de descuento', 'discount_value' => 'valor del descuento',
            'max_discount_cop' => 'descuento máximo', 'min_subtotal_cop' => 'compra mínima', 'max_uses' => 'máximo de usos',
            'starts_at' => 'fecha de inicio', 'ends_at' => 'fecha de fin',
        ];
    }
}
