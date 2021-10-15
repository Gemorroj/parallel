<?php

namespace Amp\Parallel\Test\Worker;

use Amp\CancellationToken;
use Amp\Future;
use Amp\Parallel\Context\StatusError;
use Amp\Parallel\Sync\ContextPanicError;
use Amp\Parallel\Sync\SerializationException;
use Amp\Parallel\Worker\BasicEnvironment;
use Amp\Parallel\Worker\Environment;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\TaskCancelledException;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\Worker;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellationToken;
use function Amp\coroutine;
use function Amp\delay;
use function Revolt\launch;

class NonAutoloadableTask implements Task
{
    public function run(Environment $environment, CancellationToken $token): int
    {
        return 1;
    }
}

abstract class AbstractWorkerTest extends AsyncTestCase
{
    public function testWorkerConstantDefined()
    {
        $worker = $this->createWorker();
        self::assertTrue($worker->enqueue(new Fixtures\ConstantTask));
        $worker->shutdown();
    }

    public function testIsRunning()
    {
        $worker = $this->createWorker();
        self::assertTrue($worker->isRunning());

        $worker->enqueue(new Fixtures\TestTask(42)); // Enqueue a task to start the worker.

        self::assertTrue($worker->isRunning());

        $worker->shutdown();
        self::assertFalse($worker->isRunning());
    }

    public function testIsIdleOnStart()
    {
        $worker = $this->createWorker();

        self::assertTrue($worker->isIdle());

        $worker->shutdown();
    }

    public function testEnqueueShouldThrowStatusError()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('The worker has been shut down');

        $worker = $this->createWorker();

        self::assertTrue($worker->isIdle());

        $worker->shutdown();
        $worker->enqueue(new Fixtures\TestTask(42));
    }

    public function testEnqueue()
    {
        $worker = $this->createWorker();

        $returnValue = $worker->enqueue(new Fixtures\TestTask(42));
        self::assertEquals(42, $returnValue);

        $worker->shutdown();
    }

    public function testEnqueueMultipleSynchronous()
    {
        $worker = $this->createWorker();

        $futures = [
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(42))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(56))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(72))),
        ];

        self::assertEquals([42, 56, 72], Future\all($futures));

        $worker->shutdown();
    }

    public function testEnqueueMultipleAsynchronous()
    {
        $this->setTimeout(0.4);

        $worker = $this->createWorker();

        $futures = [
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(42, 0.2))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(56, 0.3))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(72, 0.1))),
        ];

        self::assertEquals([2 => 72, 0 => 42, 1 => 56], Future\all($futures));

        $worker->shutdown();
    }

    public function testEnqueueMultipleThenShutdown()
    {
        $this->setTimeout(0.4);

        $worker = $this->createWorker();

        $futures = [
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(42, 0.2))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(56, 0.3))),
            coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(72, 0.1))),
        ];

        // Send shutdown signal, but don't await until tasks have finished.
        $shutdown = coroutine(fn () => $worker->shutdown());

        self::assertEquals([2 => 72, 0 => 42, 1 => 56], Future\all($futures));

        $shutdown->await(); // Await shutdown before ending test.
    }

    public function testNotIdleOnEnqueue()
    {
        $worker = $this->createWorker();

        $future = coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(42)));
        delay(0); // Tick event loop to call Worker::enqueue()
        self::assertFalse($worker->isIdle());
        $future->await();

        $worker->shutdown();
    }

    public function testKill(): void
    {
        $this->setTimeout(500);

        $worker = $this->createWorker();

        launch(fn () => $worker->enqueue(new Fixtures\TestTask(42)));

        $worker->kill();

        self::assertFalse($worker->isRunning());
    }

    public function testFailingTaskWithException()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\FailingTask(\Exception::class));
        } catch (TaskFailureException $exception) {
            self::assertSame(\Exception::class, $exception->getOriginalClassName());
        }

        $worker->shutdown();
    }

    public function testFailingTaskWithError()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\FailingTask(\Error::class));
        } catch (TaskFailureError $exception) {
            self::assertSame(\Error::class, $exception->getOriginalClassName());
        }

        $worker->shutdown();
    }

    public function testFailingTaskWithPreviousException()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\FailingTask(\Error::class, \Exception::class));
        } catch (TaskFailureError $exception) {
            self::assertSame(\Error::class, $exception->getOriginalClassName());
            $previous = $exception->getPrevious();
            self::assertInstanceOf(TaskFailureException::class, $previous);
            self::assertSame(\Exception::class, $previous->getOriginalClassName());
        }

        $worker->shutdown();
    }

    public function testNonAutoloadableTask()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new NonAutoloadableTask);
            self::fail("Tasks that cannot be autoloaded should throw an exception");
        } catch (TaskFailureError $exception) {
            self::assertSame("Error", $exception->getOriginalClassName());
            self::assertGreaterThan(
                0,
                \strpos($exception->getMessage(), \sprintf("Classes implementing %s", Task::class))
            );
        }

        $worker->shutdown();
    }

    public function testUnserializableTask()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new class implements Task { // Anonymous classes are not serializable.
                public function run(Environment $environment, CancellationToken $token): mixed
                {
                }
            });
            self::fail("Tasks that cannot be serialized should throw an exception");
        } catch (SerializationException $exception) {
            self::assertSame(0, \strpos($exception->getMessage(), "The given data could not be serialized"));
        }

        $worker->shutdown();
    }

    public function testUnserializableResult()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\UnserializableResultTask);
            self::fail("Tasks results that cannot be serialized should throw an exception");
        } catch (TaskFailureException $exception) {
            self::assertSame(
                0,
                \strpos($exception->getMessage(), "Uncaught Amp\Serialization\SerializationException in worker")
            );
        }

        $worker->shutdown();
    }

    public function testNonAutoloadableResult()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\NonAutoloadableResultTask);
            self::fail("Tasks results that cannot be autoloaded should throw an exception");
        } catch (\Error $exception) {
            self::assertSame(0, \strpos(
                $exception->getMessage(),
                "Class instances returned from Amp\Parallel\Worker\Task::run() must be autoloadable by the Composer autoloader"
            ));
        }

        $worker->shutdown();
    }

    public function testUnserializableTaskFollowedByValidTask()
    {
        $worker = $this->createWorker();

        $future1 = coroutine(fn () => $worker->enqueue(new class implements Task { // Anonymous classes are not serializable.
            public function run(Environment $environment, CancellationToken $token): mixed
            {
                return null;
            }
        }));
        $future2 = coroutine(fn () => $worker->enqueue(new Fixtures\TestTask(42)));

        self::assertSame(42, $future2->await());

        $worker->shutdown();
    }

    public function testCustomAutoloader()
    {
        $worker = $this->createWorker(BasicEnvironment::class, __DIR__ . '/Fixtures/custom-bootstrap.php');

        self::assertTrue($worker->enqueue(new Fixtures\AutoloadTestTask));

        $worker->shutdown();
    }

    public function testInvalidCustomAutoloader()
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('No file found at bootstrap file path given');

        $worker = $this->createWorker(BasicEnvironment::class, __DIR__ . '/Fixtures/not-found.php');

        $worker->enqueue(new Fixtures\AutoloadTestTask);

        $worker->shutdown();
    }

    public function testCancellableTask()
    {
        $this->expectException(TaskCancelledException::class);

        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\CancellingTask, new TimeoutCancellationToken(0.1));
        } finally {
            $worker->shutdown();
        }
    }

    public function testEnqueueAfterCancelledTask()
    {
        $worker = $this->createWorker();

        try {
            $worker->enqueue(new Fixtures\CancellingTask, new TimeoutCancellationToken(0.1));
            self::fail(TaskCancelledException::class . ' did not fail enqueue promise');
        } catch (TaskCancelledException $exception) {
            // Task should be cancelled, ignore this exception.
        }

        self::assertTrue($worker->enqueue(new Fixtures\ConstantTask));

        $worker->shutdown();
    }

    public function testCancellingCompletedTask()
    {
        $worker = $this->createWorker();

        self::assertTrue($worker->enqueue(new Fixtures\ConstantTask(), new TimeoutCancellationToken(0.1)));

        $worker->shutdown();
    }

    /**
     * @param string $envClassName
     * @param string|null $autoloadPath
     *
     * @return Worker
     */
    abstract protected function createWorker(
        string $envClassName = BasicEnvironment::class,
        string $autoloadPath = null
    ): Worker;
}
