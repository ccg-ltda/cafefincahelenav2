<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Builds the `{field: [before, after]}` map of an audit row: only what changed, in readable values. */
class ActivityDiff
{
    /** Changes of a model that was filled but not saved yet (call it between fill() and save()). */
    public static function dirty(Model $model, ?array $only = null): array
    {
        $before = $after = [];
        foreach ($model->getDirty() as $field => $value) {
            if ($only !== null && ! in_array($field, $only, true)) {
                continue;
            }
            $before[$field] = $model->getOriginal($field);
            $after[$field] = $model->getAttribute($field);
        }

        return self::between($before, $after);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: int|string|null, 1: int|string|null}>
     */
    public static function between(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $new) {
            $old = self::readable($before[$field] ?? null);
            $new = self::readable($new);
            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }

        return $changes;
    }

    /** Booleans as Sí/No, dates in ISO 8601 (UTC), COP and counts as integers, long text cut. */
    public static function readable(mixed $value): int|string|null
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? 'Sí' : 'No',
            $value instanceof \BackedEnum => $value->value,
            $value instanceof DateTimeInterface => Carbon::instance($value)->utc()->toIso8601String(),
            is_int($value) => $value,
            is_float($value) => $value == (int) $value ? (int) $value : (string) $value,
            is_array($value) => mb_strimwidth(json_encode($value, JSON_UNESCAPED_UNICODE), 0, 300, '…'),
            default => is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : mb_strimwidth((string) $value, 0, 300, '…'),
        };
    }
}
