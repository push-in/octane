<?php

use Laravel\Octane\Tables\TableFactory;

require_once __DIR__.'/../src/Tables/TableFactory.php';

$tables = [];

foreach ($serverState['octaneConfig']['tables'] ?? [] as $name => $columns) {
    $table = TableFactory::make(explode(':', $name)[1] ?? 1000, 'octane.table.'.sha1(explode(':', $name)[0]));

    foreach ($columns ?? [] as $columnName => $column) {
        $table->column($columnName, match (explode(':', $column)[0] ?? 'string') {
            'string' => TableFactory::TYPE_STRING,
            'int' => TableFactory::TYPE_INT,
            'float' => TableFactory::TYPE_FLOAT,
        }, explode(':', $column)[1] ?? 1000);
    }

    $table->create();

    $tables[explode(':', $name)[0]] = $table;
}

return $tables;
