<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Report;

use function escapeshellarg;

use Esoul\TestEvidence\TestRecord;

use function preg_quote;
use function str_replace;

/**
 * Builds the command that re-runs exactly one recorded test.
 *
 * PHPUnit's `name@data set` shorthand is matched unanchored and unescaped, so it can select other
 * tests with the same prefix. The filter is therefore a full regular expression anchored on the
 * fully qualified test name, which PHPUnit uses as-is.
 */
final class RerunCommand
{
    public const string DEFAULT_TEMPLATE = 'vendor/bin/phpunit --filter {filter}';

    public static function filter(TestRecord $record): string
    {
        // preg_quote() also escapes ":", "#" and "-", which are literal here; unescaping keeps the command readable.
        return '/^' . str_replace(['\:', '\#', '\-'], [':', '#', '-'], preg_quote($record->id(), '/')) . '$/';
    }

    public static function forRecord(TestRecord $record, string $template): string
    {
        return str_replace('{filter}', escapeshellarg(self::filter($record)), $template);
    }
}
