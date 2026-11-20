<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['marketing_opt_in_at' => 'datetime', 'marketing_opt_out_at' => 'datetime'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The customer accepted promotions and has not asked to stop since (a newer opt-in in a purchase re-activates). */
    public function acceptsMarketing(): bool
    {
        return $this->marketing_opt_in_at !== null
            && ($this->marketing_opt_out_at === null || $this->marketing_opt_in_at->gt($this->marketing_opt_out_at));
    }
}
