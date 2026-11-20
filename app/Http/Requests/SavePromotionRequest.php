<?php

namespace App\Http\Requests;

use App\Services\PromotionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a promotion form. Business rules (price limits, dates, overlaps) are in PromotionService::validateForSave.
 * `starts_at` / `ends_at` arrive as "YYYY-MM-DDTHH:mm" in Colombian time.
 */
class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route group already requires an administrator.
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1', 'max:999999999999', 'exists:products,id'],
            'variant_ids' => ['required', 'array', 'min:1', 'max:100'],
            'variant_ids.*' => ['integer', 'distinct', 'min:1', 'max:999999999999'],
            'discount_type' => ['required', Rule::in(PromotionService::TYPES)],
            'discount_value' => ['required', 'integer', 'min:1', 'max:100000000'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'variant_ids.required' => 'Selecciona al menos una presentación.',
            'variant_ids.min' => 'Selecciona al menos una presentación.',
            'starts_at.date_format' => 'La fecha de inicio no es válida.',
            'ends_at.date_format' => 'La fecha de fin no es válida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'producto', 'variant_ids' => 'presentaciones', 'discount_type' => 'tipo de descuento',
            'discount_value' => 'valor del descuento', 'is_active' => 'estado', 'starts_at' => 'fecha de inicio', 'ends_at' => 'fecha de fin',
        ];
    }
}
