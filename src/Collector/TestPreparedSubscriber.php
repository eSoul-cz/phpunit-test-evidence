<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * @internal
 */
final readonly class TestPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(Prepared $event): void
    {
        $this->collector->prepared($event->test(), $event->telemetryInfo()->time());
    }
}
