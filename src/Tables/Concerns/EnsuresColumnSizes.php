<?php

namespace Laravel\Octane\Tables\Concerns;

use Illuminate\Support\Arr;
use Laravel\Octane\Exceptions\ValueTooLargeForColumnException;

trait EnsuresColumnSizes
{
    /**
     * Ensures the given column value is within the given size.
     *
     * @return void
     */
    protected function ensureColumnsSize()
    {
        return function ($value, $column) {
            if (! Arr::has($this->columns, $column)) {
                return;
            }

            [$type, $size] = $this->columns[$column];

            if ($type == static::TYPE_STRING && strlen($value) > $size) {
                throw new ValueTooLargeForColumnException(sprintf(
                    'Value [%s...] is too large for [%s] column.',
                    substr($value, 0, 20),
                    $column,
                ));
            }
        };
    }
}
