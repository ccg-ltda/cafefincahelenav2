<?php

namespace App\Exceptions;

use RuntimeException;

/** A new payment link cannot be generated (not a manual order, a valid link already exists, the customer is already paying). */
class PaymentLinkException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
