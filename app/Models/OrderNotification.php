<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One transactional email of an order for one recipient (unique per order + type + recipient, so it is never sent twice). */
class OrderNotification extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
