<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * @internal
 */
final readonly class TestSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(
        private ResultCollector $collector,
    ) {}

    public function notify(Skipped $event): void
    {
        $this->collector->skipped($event->test(), $event->message());
    }
}
