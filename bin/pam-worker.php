<?php

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\Exceptions\TaskExceptionResult;
use Laravel\Octane\Pam\PamClient;
use Laravel\Octane\Pam\PamTaskDispatcher;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;
use Pam\Http\Server as PamServer;
use Pam\Shared\Tasks;
use Symfony\Component\Console\Output\ConsoleOutput;

if (! class_exists(PamServer::class)) {
    echo 'PAM must be used to run this script.';

    exit(1);
}

$basePath = require __DIR__.'/bootstrap.php';

/*
|--------------------------------------------------------------------------
| Start The Octane Worker
|--------------------------------------------------------------------------
|
| Next we will start the Octane worker, which is a long running process to
| handle incoming requests to the application. This worker will be used
| by PAM to serve an entire Laravel application at high speed.
|
*/

$pamClient = new PamClient;

/*
|--------------------------------------------------------------------------
| Shared Tables, Cache And Task Workers
|--------------------------------------------------------------------------
|
| PAM keeps Octane's tables and cache table in shared memory, so every worker
| of the server sees the same rows, as with Swoole. Task workers started with
| --task-workers run concurrent tasks and receive the server's ticks.
|
*/

$serverState = ['octaneConfig' => json_decode((string) ($_SERVER['OCTANE_CONFIG'] ?? '[]'), true) ?: []];

$initialInstances = [];

if (class_exists(\Pam\Shared\Table::class, false)) {
    $initialInstances['octane.tables'] = require __DIR__.'/createSwooleTables.php';

    if ($cacheTable = require __DIR__.'/createSwooleCacheTable.php') {
        $initialInstances['octane.cacheTable'] = $cacheTable;
    }

    if (Tasks::available()) {
        $initialInstances[DispatchesTasks::class] = new PamTaskDispatcher;
    }
}

try {
    $worker = tap(new Worker(
        new ApplicationFactory($basePath), $pamClient
    ))->boot($initialInstances);
} catch (Throwable $e) {
    $container = Container::getInstance();

    $container->bound(ExceptionHandler::class)
        ? $container->make(ExceptionHandler::class)->renderForConsole(new ConsoleOutput, $e)
        : fwrite(STDERR, (string) $e.PHP_EOL);

    exit(1);
}

$app = $worker->application();

$octaneConfig = $app['config']->get('octane');

$debugMode = $app['config']->get('app.debug');

$requestStartTime = 0;

$worker->onRequestHandled(function ($request, $response, $sandbox) use (&$requestStartTime) {
    if (! $sandbox->environment('local', 'testing')) {
        return;
    }

    Stream::request(
        $request->getMethod(),
        $request->fullUrl(),
        $response->getStatusCode(),
        (microtime(true) - $requestStartTime) * 1000,
    );
});

$maxExecutionTime = (int) ($_SERVER['REQUEST_MAX_EXECUTION_TIME'] ?? $octaneConfig['max_execution_time'] ?? 30);

/*
|--------------------------------------------------------------------------
| Handle Incoming Requests
|--------------------------------------------------------------------------
|
| PAM owns the sockets, HTTP parsing, worker supervision and request time
| limits. For every request it fills the PHP request globals and invokes
| this handler, which hands the request to the warm Octane worker.
|
*/

if (class_exists(Tasks::class, false) && Tasks::isTaskWorker()) {
    $taskToken = (string) getenv('PAM_TASK_TOKEN');

    $ticks = (bool) ($octaneConfig['tick'] ?? true);

    PamServer::createNative(function ($pamResponse) use ($worker, $taskToken, $ticks) {
        if (strlen($taskToken) < 32 || ! hash_equals($taskToken, (string) ($_SERVER['HTTP_X_PAM_TASK_TOKEN'] ?? ''))) {
            return $pamResponse->status(403)->send('');
        }

        $kind = $_SERVER['HTTP_X_PAM_TASK'] ?? '';

        if ($kind === 'tick') {
            if ($ticks) {
                $worker->handleTick();
            }

            return $pamResponse->status(204)->send('');
        }

        $result = $worker->handleTask(unserialize((string) file_get_contents('php://input')));

        if ($kind === 'dispatch') {
            return $pamResponse->status(204)->send('');
        }

        try {
            $body = serialize($result);
        } catch (Throwable $e) {
            $body = serialize(TaskExceptionResult::from($e));
        }

        return $pamResponse->status(200)->header('Content-Type', 'application/octet-stream')->send($body);
    })->listen((int) $_SERVER['OCTANE_PORT'], $_SERVER['OCTANE_HOST'], [
        'maxConcurrentRequests' => 1,
        'maxBodyBytes' => 64 * 1024 * 1024,
        'requestTimeoutMs' => $maxExecutionTime > 0 ? $maxExecutionTime * 1000 : 365 * 24 * 3600 * 1000,
    ]);

    register_shutdown_function(fn () => $worker->terminate());

    return;
}

PamServer::createNative(function ($pamResponse) use ($worker, $pamClient, $app, $octaneConfig, $debugMode, &$requestStartTime) {
    $requestStartTime = microtime(true);

    try {
        [$request, $context] = $pamClient->marshalRequest(new RequestContext([
            'pamResponse' => $pamResponse,
            'publicPath' => $app->publicPath(),
            'octaneConfig' => $octaneConfig,
        ]));

        $worker->handle($request, $context);
    } catch (Throwable $e) {
        report($e);

        @http_response_code(500);
        header('Content-Type: text/plain');

        echo $debugMode ? (string) $e : 'Internal Server Error';

        Stream::shutdown($e);
    }

    return $pamResponse;
})->listen((int) $_SERVER['OCTANE_PORT'], $_SERVER['OCTANE_HOST'], array_filter([
    'maxConcurrentRequests' => 1,
    'maxResponseBytes' => 256 * 1024 * 1024,
    'requestTimeoutMs' => $maxExecutionTime > 0 ? $maxExecutionTime * 1000 : 365 * 24 * 3600 * 1000,
    'tlsCert' => $_SERVER['PAM_TLS_CERT'] ?? null,
    'tlsKey' => $_SERVER['PAM_TLS_KEY'] ?? null,
]));

// PAM starts serving once this script returns, and shuts the engine down when the worker stops...
register_shutdown_function(fn () => $worker->terminate());
