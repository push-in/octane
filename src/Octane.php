<?php

namespace Laravel\Octane;

use Exception;
use Laravel\Octane\Swoole\WorkerState;
use Laravel\Octane\Tables\PamTable;
use Swoole\Http\Server;
use Swoole\Table;
use Throwable;

class Octane
{
    use Concerns\ProvidesConcurrencySupport;
    use Concerns\ProvidesDefaultConfigurationOptions;
    use Concerns\ProvidesRouting;
    use Concerns\RegistersTickHandlers;

    /**
     * Get a Swoole (or PAM) table instance.
     */
    public function table(string $table): Table|PamTable
    {
        $tables = match (true) {
            app()->bound(Server::class) => app(WorkerState::class)->tables,
            app()->bound('octane.tables') => app('octane.tables'),
            default => throw new Exception('Tables may only be accessed when using the Swoole or PAM server.'),
        };

        if (! isset($tables[$table])) {
            throw new Exception("Swoole table [{$table}] has not been configured.");
        }

        return $tables[$table];
    }

    /**
     * Format an exception to a string that should be returned to the client.
     */
    public static function formatExceptionForClient(Throwable $e, bool $debug = false): string
    {
        return $debug ? (string) $e : 'Internal server error.';
    }

    /**
     * Write an error message to STDERR or to the SAPI logger if not in CLI mode.
     */
    public static function writeError(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message.PHP_EOL);

            return;
        }

        error_log($message, 4);
    }

    /**
     * Register the Octane dev commands.
     */
    public static function registerDevCommands(): void
    {
        if (class_exists(\Illuminate\Foundation\DevCommands::class)) {
            \Illuminate\Foundation\DevCommands::artisan('octane:start --watch', 'server');
        }
    }
}
