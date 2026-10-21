<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * @internal
 */
final readonly class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(Finished $event): void
    {
        $this->collector->finished($event->test(), $event->telemetryInfo()->time(), $event->numberOfAssertionsPerformed());
    }
}
