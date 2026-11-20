<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id', 'status', 'external_transaction_id', 'confirmed_at', 'verified_at'];

    protected function casts(): array
    {
        return ['amount_cop' => 'integer', 'confirmed_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
