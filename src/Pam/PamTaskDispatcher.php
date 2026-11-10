<?php

namespace Laravel\Octane\Pam;

use Closure;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\Exceptions\TaskException;
use Laravel\Octane\Exceptions\TaskExceptionResult;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Laravel\Octane\Swoole\TaskResult;
use Laravel\SerializableClosure\SerializableClosure;
use Pam\Shared\Tasks;
use RuntimeException;

class PamTaskDispatcher implements DispatchesTasks
{
    /**
     * Concurrently resolve the given callbacks via PAM task workers, returning the results.
     *
     * Results will be keyed by their given keys - if a task did not finish, the tasks value will be "false".
     *
     *
     * @throws \Laravel\Octane\Exceptions\TaskException
     * @throws \Laravel\Octane\Exceptions\TaskTimeoutException
     */
    public function resolve(array $tasks, int $waitMilliseconds = 3000): array
    {
        if ($tasks === []) {
            return [];
        }

        try {
            $results = Tasks::resolve($this->payloads($tasks), max(1, $waitMilliseconds));
        } catch (RuntimeException $e) {
            throw new TaskException($e::class, $e->getMessage(), (int) $e->getCode(), $e->getFile(), $e->getLine());
        }

        $finished = 0;
        $i = 0;

        foreach ($tasks as $key => $task) {
            $result = isset($results[$i]) ? unserialize($results[$i]) : null;

            $i++;

            if (is_null($result)) {
                $tasks[$key] = false;

                continue;
            }

            if ($result instanceof TaskExceptionResult) {
                throw $result->getOriginal();
            }

            $finished++;

            $tasks[$key] = $result instanceof TaskResult ? $result->result : $result;
        }

        if ($finished === 0) {
            throw TaskTimeoutException::after($waitMilliseconds);
        }

        return $tasks;
    }

    /**
     * Concurrently dispatch the given callbacks via PAM task workers.
     */
    public function dispatch(array $tasks): void
    {
        try {
            Tasks::dispatch($this->payloads($tasks));
        } catch (RuntimeException $e) {
            throw new TaskException($e::class, $e->getMessage(), (int) $e->getCode(), $e->getFile(), $e->getLine());
        }
    }

    /**
     * Serialize the given tasks for the task workers.
     *
     * @return list<string>
     */
    protected function payloads(array $tasks): array
    {
        return array_values(array_map(
            fn ($task) => serialize($task instanceof Closure ? new SerializableClosure($task) : $task),
            $tasks,
        ));
    }
}
