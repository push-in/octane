<?php

use Laravel\Octane\Tables\TableFactory;

require_once __DIR__.'/../src/Tables/TableFactory.php';

if (($serverState['octaneConfig']['max_execution_time'] ?? 0) > 0) {
    $timerTable = TableFactory::make($serverState['octaneConfig']['max_timer_table_size'] ?? 250);

    $timerTable->column('worker_pid', TableFactory::TYPE_INT);
    $timerTable->column('time', TableFactory::TYPE_INT);
    $timerTable->column('fd', TableFactory::TYPE_INT);

    $timerTable->create();

    return $timerTable;
}

return null;
