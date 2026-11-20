<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One webhook received, keyed by its checksum (so the same event twice does nothing). No customer data, no secret, no raw body. */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_in_cents' => 'integer'];
    }
}
