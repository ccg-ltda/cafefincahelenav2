<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** An order moved to another status (`$by` is null when the system did it, e.g. a payment). Dispatched after the commit. */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Order $order, public readonly string $from, public readonly string $to, public readonly ?User $by = null) {}
}
