<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/**
 * @internal
 */
final readonly class TestErroredSubscriber implements ErroredSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(Errored $event): void
    {
        $this->collector->errored($event->test(), $event->throwable());
    }
}
