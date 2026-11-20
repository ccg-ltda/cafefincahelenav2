<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Somebody else already moved the order: the screen the person is looking at is stale. */
class OrderMovedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $status)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'status' => $this->status, 'moved' => true], 409)->header('Cache-Control', 'private, no-store');
    }
}
