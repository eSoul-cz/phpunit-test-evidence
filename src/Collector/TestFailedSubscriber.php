<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/**
 * @internal
 */
final readonly class TestFailedSubscriber implements FailedSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(Failed $event): void
    {
        $this->collector->failed(
            $event->test(),
            $event->throwable(),
            $event->hasComparisonFailure() ? $event->comparisonFailure()->diff() : null,
        );
    }
}
