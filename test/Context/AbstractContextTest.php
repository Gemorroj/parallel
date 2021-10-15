<?php

namespace Amp\Parallel\Test\Context;

use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Sync\ContextPanicError;
use Amp\PHPUnit\AsyncTestCase;
use function Amp\coroutine;
use function Amp\delay;

abstract class AbstractContextTest extends AsyncTestCase
{
    abstract public function createContext(string|array $script): Context;

    public function testBasicProcess(): void
    {
        $context = $this->createContext([
                __DIR__ . "/Fixtures/test-process.php",
                "Test"
            ]);
        $context->start();
        self::assertSame("Test", $context->join());
    }

    public function testFailingProcess(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('No string provided');

        $context = $this->createContext(__DIR__ . "/Fixtures/test-process.php");
        $context->start();
        $context->join();
    }

    public function testThrowingProcessOnReceive(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('Test message');

        $context = $this->createContext(__DIR__ . "/Fixtures/throwing-process.php");
        $context->start();
        $context->receive();
    }

    public function testThrowingProcessOnSend(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('Test message');

        $context = $this->createContext(__DIR__ . "/Fixtures/throwing-process.php");
        $context->start();
        delay(0.1);
        $context->send(1);
    }

    public function testInvalidScriptPath(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage("No script found at '../test-process.php'");

        $context = $this->createContext("../test-process.php");
        $context->start();
        $context->join();
    }

    public function testInvalidResult(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('The given data could not be serialized');

        $context = $this->createContext(__DIR__ . "/Fixtures/invalid-result-process.php");
        $context->start();
        $context->join();
    }

    public function testNoCallbackReturned(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('did not return a callable function');

        $context = $this->createContext(__DIR__ . "/Fixtures/no-callback-process.php");
        $context->start();
        $context->join();
    }

    public function testParseError(): void
    {
        $this->expectException(ContextPanicError::class);
        $this->expectExceptionMessage('contains a parse error');

        $context = $this->createContext(__DIR__ . "/Fixtures/parse-error-process.inc");
        $context->start();
        $context->join();
    }

    public function testKillWhenJoining(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext([
                __DIR__ . "/Fixtures/delayed-process.php",
                5,
            ]);
        $context->start();
        delay(0.1);
        $promise = coroutine(fn () => $context->join());
        $context->kill();
        self::assertFalse($context->isRunning());
        $promise->await();
    }

    public function testKillBusyContext(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext([
                __DIR__ . "/Fixtures/sleep-process.php",
                5,
            ]);
        $context->start();
        delay(0.1);
        $promise = coroutine(fn () => $context->join());
        $context->kill();
        self::assertFalse($context->isRunning());
        $promise->await();
    }

    public function testExitingProcess(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('Failed to receive result');

        $context = $this->createContext([
                __DIR__ . "/Fixtures/exiting-process.php",
                5,
            ]);
        $context->start();
        $context->join();
    }

    public function testExitingProcessOnReceive(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('stopped responding');

        $context = $this->createContext(__DIR__ . "/Fixtures/exiting-process.php");
        $context->start();
        $context->receive();
    }

    public function testExitingProcessOnSend(): void
    {
        $this->expectException(ContextException::class);
        $this->expectExceptionMessage('stopped responding');

        $context = $this->createContext(__DIR__ . "/Fixtures/exiting-process.php");
        $context->start();
        delay(0.5);
        $context->send(1);
    }
}
