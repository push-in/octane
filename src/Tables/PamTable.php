<?php

namespace Laravel\Octane\Tables;

use Pam\Shared\Table;

class PamTable extends Table
{
    use Concerns\EnsuresColumnSizes;

    /**
     * The table columns.
     *
     * @var array
     */
    protected $columns;

    /**
     * The longest key stored as is, like Swoole tables.
     */
    protected const MAX_KEY_BYTES = 64;

    /**
     * Create a new PAM table.
     *
     * Named tables are shared by every worker of the PAM server.
     */
    public function __construct(int $size, string $name = '')
    {
        parent::__construct($size, 1, $name, static::MAX_KEY_BYTES);
    }

    /**
     * Set the data type and size of the columns.
     */
    public function column(string $name, int $type, int $size = 0): bool
    {
        $this->columns[$name] = [$type, $size];

        return parent::column($name, $type, $size);
    }

    /**
     * Update a row of the table.
     */
    public function set(string $key, array $values): bool
    {
        collect($values)
            ->each($this->ensureColumnsSize());

        return parent::set($this->storageKey($key), $values);
    }

    /**
     * Get a row, or a column of a row, of the table.
     */
    public function get(string $key, ?string $field = null): array|int|float|string|false
    {
        return parent::get($this->storageKey($key), $field);
    }

    /**
     * Determine if a row exists in the table.
     */
    public function exists(string $key): bool
    {
        return parent::exists($this->storageKey($key));
    }

    /**
     * Delete a row of the table.
     */
    public function del(string $key): bool
    {
        return parent::del($this->storageKey($key));
    }

    /**
     * Increment a numeric column of a row.
     */
    public function incr(string $key, string $column, int|float $incrby = 1): int|float|false
    {
        return parent::incr($this->storageKey($key), $column, $incrby);
    }

    /**
     * Decrement a numeric column of a row.
     */
    public function decr(string $key, string $column, int|float $decrby = 1): int|float|false
    {
        return parent::decr($this->storageKey($key), $column, $decrby);
    }

    /**
     * Keys longer than the key size are stored under a collision-free digest
     * instead of being truncated.
     */
    protected function storageKey(string $key): string
    {
        if (strlen($key) <= static::MAX_KEY_BYTES) {
            return $key;
        }

        return substr($key, 0, static::MAX_KEY_BYTES - 41).'#'.sha1($key);
    }
}
