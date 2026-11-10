<?php

use Laravel\Octane\Tables\TableFactory;

require_once __DIR__.'/../src/Tables/TableFactory.php';

if ($serverState['octaneConfig']['cache'] ?? false) {
    $cacheTable = TableFactory::make(
        $serverState['octaneConfig']['cache']['rows'] ?? 1000,
        'octane.cache',
    );

    $cacheTable->column('value', TableFactory::TYPE_STRING, $serverState['octaneConfig']['cache']['bytes'] ?? 10000);
    $cacheTable->column('expiration', TableFactory::TYPE_INT);

    $cacheTable->create();

    return $cacheTable;
}
