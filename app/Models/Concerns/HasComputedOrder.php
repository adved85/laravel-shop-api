<?php

namespace App\Models\Concerns;

trait HasComputedOrder
{
    /**
     * Use the given order if present, otherwise the next slot after the current max.
     */
    public static function computeOrder(array $data): int
    {
        return $data['order'] ?? (static::max('order') ?? -1) + 1;
    }
}
