<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A coupon cannot be applied. The message is already safe to show the customer. */
class CouponException extends RuntimeException
{
    public const GENERIC = 'Cupón no válido o vencido';

    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => ['coupon_code' => [$this->getMessage()]], 'coupon_error' => $this->reason], 422)
            ->header('Cache-Control', 'no-store');
    }
}
