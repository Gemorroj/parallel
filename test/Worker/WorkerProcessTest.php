<?php

namespace Amp\Tests\Concurrent\Worker;

use Amp\Concurrent\Worker\WorkerProcess;

class WorkerProcessTest extends AbstractWorkerTest
{
    protected function createWorker()
    {
        return new WorkerProcess();
    }
}