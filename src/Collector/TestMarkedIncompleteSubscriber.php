<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

/**
 * @internal
 */
final readonly class TestMarkedIncompleteSubscriber implements MarkedIncompleteSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(MarkedIncomplete $event): void
    {
        $this->collector->incomplete($event->test(), $event->throwable());
    }
}
