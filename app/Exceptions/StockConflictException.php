<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The stock in the database is no longer the one the editor started from (for example a sale came in). */
class StockConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentStock)
    {
        parent::__construct("El stock cambió mientras editabas (ahora es {$currentStock}). Revisa y vuelve a guardar.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'current_stock' => $this->currentStock], 409)
            ->header('Cache-Control', 'private, no-store');
    }
}
