<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Report;

use DateTimeImmutable;
use Esoul\TestEvidence\RunResult;

/**
 * Everything a renderer needs: the evaluated run, its context and an optional baseline run.
 */
final readonly class Report
{
    public function __construct(
        public string $group,
        public RunResult $result,
        public DateTimeImmutable $generatedAt,
        public string $rerunTemplate,
        public ?string $title = null,
        public ?string $ticketId = null,
        public ?string $ticketUrl = null,
        public ?string $commit = null,
        public ?string $branch = null,
        public bool $hasUncommittedChanges = false,
        public ?Baseline $baseline = null,
    ) {}
}
