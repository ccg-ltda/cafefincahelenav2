<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable audit row. There is no updated_at, and Eloquent refuses to change or remove a row once saved. */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'changes' => 'array', 'context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial es inmutable: no se puede modificar un registro.'));
        static::deleting(fn () => throw new LogicException('El historial es inmutable: no se puede eliminar un registro.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
