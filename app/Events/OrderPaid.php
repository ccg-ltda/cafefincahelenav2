<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/** The payment of an order was approved. Dispatched after the commit; the emails (receipt, admin notice) will listen to it. */
class OrderPaid
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
