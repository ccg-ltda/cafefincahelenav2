<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** An approved payment arrived for an order that was already cancelled (the alert an administrator must review). Dispatched after the commit. */
class PaymentReceivedOnCancelledOrder
{
    use Dispatchable;

    public function __construct(public readonly Order $order, public readonly Payment $payment) {}
}
