<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Report;

use Esoul\TestEvidence\RunResult;

/**
 * The same tests run against the application code of an earlier commit (typically the commit
 * before a fix), with the test files taken from the evaluated commit.
 */
final readonly class Baseline
{
    public function __construct(
        public string $ref,
        public ?string $commit,
        public RunResult $result,
    ) {}
}
