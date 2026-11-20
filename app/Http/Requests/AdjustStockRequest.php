<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Stock modal: the exact available stock, plus the stock the modal showed (concurrency check, see InventoryService::setStock). */
class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The route group already requires an administrator.
    }

    public function rules(): array
    {
        return [
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'expected_stock' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    public function attributes(): array
    {
        return ['stock_quantity' => 'stock disponible', 'expected_stock' => 'stock original'];
    }
}
