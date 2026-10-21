<?php

declare(strict_types=1);

namespace Esoul\TestEvidence;

enum TestOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';
    case Skipped = 'skipped';
    case Incomplete = 'incomplete';

    public function isSuccessful(): bool
    {
        return $this === self::Passed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'passed',
            self::Failed => 'failed',
            self::Errored => 'error',
            self::Skipped => 'skipped',
            self::Incomplete => 'incomplete',
        };
    }
}
