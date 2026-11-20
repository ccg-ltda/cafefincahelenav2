<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id', 'status', 'external_transaction_id', 'confirmed_at', 'verified_at', 'payment_method_type', 'status_message', 'checkout_expires_at', 'review_required_at', 'reviewed_at', 'reviewed_by']; // only PaymentService writes these (forceFill)

    protected function casts(): array
    {
        return ['amount_cop' => 'integer', 'confirmed_at' => 'datetime', 'verified_at' => 'datetime', 'checkout_expires_at' => 'datetime', 'review_required_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
