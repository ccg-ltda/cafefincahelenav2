<?php

namespace App\Exceptions;

use RuntimeException;

/** A payment attempt cannot be started: the order is already paid, or it is cancelled / not waiting for payment. */
class OrderNotPayableException extends RuntimeException
{
    public function __construct(public readonly bool $alreadyPaid)
    {
        parent::__construct($alreadyPaid ? 'El pedido ya está pagado.' : 'El pedido ya no admite pago.');
    }
}
