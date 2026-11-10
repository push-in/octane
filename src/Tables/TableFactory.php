<?php

namespace Laravel\Octane\Tables;

class TableFactory
{
    /**
     * The column types (the same values as Swoole's table column types).
     */
    public const TYPE_INT = 1;

    public const TYPE_FLOAT = 2;

    public const TYPE_STRING = 3;

    /**
     * Creates a new Swoole Table with the given size.
     *
     * On PAM, the table lives in shared memory and the name identifies it
     * across the server's workers.
     *
     * @return \Swoole\Table|\Laravel\Octane\Tables\PamTable
     */
    public static function make($size, ?string $name = null)
    {
        static::ensureDependenciesAreLoaded();

        return match (true) {
            extension_loaded('openswoole') => new OpenSwooleTable($size, 1),
            extension_loaded('swoole') => new SwooleTable($size, 1),
            static::pamTablesAreAvailable() => new PamTable($size, $name ?? ''),
            default => new SwooleTable($size, 1),
        };
    }

    /**
     * Determine if PAM shared memory tables are available.
     */
    public static function pamTablesAreAvailable(): bool
    {
        return class_exists(\Pam\Shared\Table::class, false) && \Pam\Shared\Table::available();
    }

    /**
     * Determine if tables can be created in this process.
     */
    public static function supported(): bool
    {
        return extension_loaded('openswoole') || extension_loaded('swoole') || static::pamTablesAreAvailable();
    }

    /**
     * Because those tables may be required without composer
     * we ensure the table's dependencies are loaded.
     */
    protected static function ensureDependenciesAreLoaded()
    {
        require_once __DIR__.'/Concerns/EnsuresColumnSizes.php';

        match (true) {
            extension_loaded('openswoole') => require_once __DIR__.'/OpenSwooleTable.php',
            ! extension_loaded('swoole') && static::pamTablesAreAvailable() => require_once __DIR__.'/PamTable.php',
            default => require_once __DIR__.'/SwooleTable.php',
        };
    }
}
