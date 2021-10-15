<?php

namespace Amp\Parallel\Worker;

use Amp\CancellationToken;
use Amp\Deferred;
use Amp\Future;
use Amp\Parallel\Context\StatusError;
use function Amp\coroutine;
use function Revolt\launch;

/**
 * Provides a pool of workers that can be used to execute multiple tasks asynchronously.
 *
 * A worker pool is a collection of worker threads that can perform multiple
 * tasks simultaneously. The load on each worker is balanced such that tasks
 * are completed as soon as possible and workers are used efficiently.
 */
final class DefaultPool implements Pool
{
    /** @var bool Indicates if the pool is currently running. */
    private bool $running = true;

    /** @var int The maximum number of workers the pool should spawn. */
    private int $maxSize;

    /** @var WorkerFactory A worker factory to be used to create new workers. */
    private WorkerFactory $factory;

    /** @var \SplObjectStorage A collection of all workers in the pool. */
    private \SplObjectStorage $workers;

    /** @var \SplQueue A collection of idle workers. */
    private \SplQueue $idleWorkers;

    private ?Deferred $waiting = null;

    private \Closure $push;

    private ?Future $exitStatus = null;

    /**
     * Creates a new worker pool.
     *
     * @param int $maxSize The maximum number of workers the pool should spawn.
     *     Defaults to `Pool::DEFAULT_MAX_SIZE`.
     * @param WorkerFactory|null $factory A worker factory to be used to create
     *     new workers.
     *
     * @throws \Error
     */
    public function __construct(int $maxSize = self::DEFAULT_MAX_SIZE, WorkerFactory $factory = null)
    {
        if ($maxSize < 0) {
            throw new \Error("Maximum size must be a non-negative integer");
        }

        $this->maxSize = $maxSize;

        // Use the global factory if none is given.
        $this->factory = $factory ?? factory();

        $this->workers = new \SplObjectStorage;
        $this->idleWorkers = new \SplQueue;

        $workers = $this->workers;
        $idleWorkers = $this->idleWorkers;
        $waiting = &$this->waiting;

        $this->push = static function (Worker $worker) use (&$waiting, $workers, $idleWorkers): void {
            if (!$workers->contains($worker)) {
                return;
            }

            $idleWorkers->push($worker);

            if ($waiting !== null) {
                $deferred = $waiting;
                $waiting = null;
                $deferred->complete($worker);
            }
        };
    }

    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->kill();
        }
    }

    /**
     * Checks if the pool is running.
     *
     * @return bool True if the pool is running, otherwise false.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Checks if the pool has any idle workers.
     *
     * @return bool True if the pool has at least one idle worker, otherwise false.
     */
    public function isIdle(): bool
    {
        return $this->idleWorkers->count() > 0 || $this->workers->count() === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * {@inheritdoc}
     */
    public function getWorkerCount(): int
    {
        return $this->workers->count();
    }

    /**
     * {@inheritdoc}
     */
    public function getIdleWorkerCount(): int
    {
        return $this->idleWorkers->count();
    }

    /**
     * Enqueues a {@see Task} to be executed by the worker pool.
     *
     * @param Task $task The task to enqueue.
     * @param CancellationToken|null $token
     *
     * @return mixed The return (or resolution) value of {@see Task::run()}.
     *
     * @throws StatusError If the pool has been shutdown.
     * @throws TaskFailureThrowable If the task throws an exception.
     */
    public function enqueue(Task $task, ?CancellationToken $token = null): mixed
    {
        $worker = $this->pull();

        try {
            $result = $worker->enqueue($task, $token);
        } finally {
            ($this->push)($worker);
        }

        return $result;
    }

    /**
     * Shuts down the pool and all workers in it.
     *
     * @return int
     *
     * @throws StatusError If the pool has not been started.
     */
    public function shutdown(): int
    {
        if ($this->exitStatus) {
            return $this->exitStatus->await();
        }

        $this->running = false;

        $shutdowns = [];
        foreach ($this->workers as $worker) {
            \assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $shutdowns[] = coroutine(fn () => $worker->shutdown());
            }
        }

        if ($this->waiting !== null) {
            $deferred = $this->waiting;
            $this->waiting = null;
            $deferred->error(new WorkerException('The pool shutdown before the task could be executed'));
        }

        return ($this->exitStatus = coroutine(function () use ($shutdowns): int {
            $shutdowns = Future\all($shutdowns);
            if (\array_sum($shutdowns)) {
                return 1;
            }
            return 0;
        }))->await();
    }

    /**
     * Kills all workers in the pool and halts the worker pool.
     */
    public function kill(): void
    {
        $this->running = false;

        foreach ($this->workers as $worker) {
            \assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $worker->kill();
            }
        }

        if ($this->waiting !== null) {
            $deferred = $this->waiting;
            $this->waiting = null;
            $deferred->error(new WorkerException('The pool was killed before the task could be executed'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getWorker(): Worker
    {
        return new Internal\PooledWorker($this->pull(), $this->push);
    }

    /**
     * Pulls a worker from the pool.
     *
     * @throws StatusError
     */
    private function pull(): Worker
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shutdown");
        }

        do {
            if ($this->idleWorkers->isEmpty()) {
                if ($this->getWorkerCount() < $this->maxSize) {
                    // Max worker count has not been reached, so create another worker.
                    $worker = $this->factory->create();
                    if (!$worker->isRunning()) {
                        throw new WorkerException('Worker factory did not create a viable worker');
                    }
                    $this->workers->attach($worker, 0);
                    return $worker;
                }

                if ($this->waiting === null) {
                    $this->waiting = new Deferred;
                }

                do {
                    $worker = $this->waiting->getFuture()->join();
                } while ($this->waiting !== null);
            } else {
                // Shift a worker off the idle queue.
                $worker = $this->idleWorkers->shift();
            }

            \assert($worker instanceof Worker);

            if ($worker->isRunning()) {
                return $worker;
            }

            // Worker crashed; trigger error and remove it from the pool.

            launch(function () use ($worker): void {
                try {
                    $code = $worker->shutdown();
                    \trigger_error('Worker in pool exited unexpectedly with code ' . $code, \E_USER_WARNING);
                } catch (\Throwable $exception) {
                    \trigger_error(
                        'Worker in pool crashed with exception on shutdown: ' . $exception->getMessage(),
                        \E_USER_WARNING
                    );
                }
            });

            $this->workers->detach($worker);
        } while (true);
    }
}
