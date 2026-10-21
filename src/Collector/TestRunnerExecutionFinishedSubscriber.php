<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;

/**
 * @internal
 */
final readonly class TestRunnerExecutionFinishedSubscriber implements ExecutionFinishedSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(ExecutionFinished $event): void
    {
        $this->collector->executionFinished();
    }
}
