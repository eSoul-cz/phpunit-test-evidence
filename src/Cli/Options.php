<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Cli;

use function array_is_list;
use function array_shift;

use Esoul\TestEvidence\Report\RerunCommand;

use function file_get_contents;
use function in_array;

use InvalidArgumentException;

use function is_array;
use function is_file;
use function is_string;
use function json_decode;

use JsonException;

use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Command-line options, with defaults from `extra.test-evidence` in the project's composer.json.
 */
final readonly class Options
{
    public const string USAGE = <<<'TXT'
        Usage: test-evidence <group> [options] [-- <phpunit arguments>]

        Runs the PHPUnit tests of <group> and prints a Markdown evidence report.

        Options:
          --baseline=<git ref>      Also run the tests against the code at <git ref> (e.g. the commit before a fix)
          --vendor=copy|install     How the baseline gets its vendor directory (default: copy)
          --copy=<path>             File or directory copied into the baseline checkout (repeatable, e.g. .env)
          --php-ini=<key=value>     PHP ini setting for the PHPUnit process (repeatable, e.g. memory_limit=1G)
          --phpunit=<path>          PHPUnit binary (default: vendor/bin/phpunit)
          --rerun=<template>        Re-run command; {filter} is replaced (default: vendor/bin/phpunit --filter {filter})
          --ticket-prefix=<prefix>  Group prefix stripped to get the ticket ID (e.g. ticket-)
          --ticket-url=<template>   Ticket link; {id} and {group} are replaced
          --title=<text>            Report title
          --format=markdown|json    Output format (default: markdown)
          --output=<file>           Write the report to <file>; {group} is replaced, missing directories are
                                    created, a relative path is relative to the project (default: stdout)
          --output=-                Print the report to stdout, overriding a configured output
          --help                    Show this help

        Defaults for every option except <group>, --baseline, --title and --format can be set in
        composer.json under "extra": {"test-evidence": {...}}, using the option names as keys.
        TXT;

    private const array SCALAR_OPTIONS = ['baseline', 'vendor', 'phpunit', 'rerun', 'ticket-prefix', 'ticket-url', 'title', 'format', 'output'];

    private const array LIST_OPTIONS = ['copy', 'php-ini'];

    private const array CONFIGURABLE_OPTIONS = ['vendor', 'phpunit', 'rerun', 'ticket-prefix', 'ticket-url', 'copy', 'php-ini', 'output'];

    /**
     * @param  list<string>  $copyPaths
     * @param  list<string>  $phpIni
     * @param  list<string>  $phpunitArguments
     */
    public function __construct(
        public string $group,
        public ?string $baseline,
        public string $vendorStrategy,
        public array $copyPaths,
        public array $phpIni,
        public string $phpunit,
        public string $rerunTemplate,
        public ?string $ticketPrefix,
        public ?string $ticketUrl,
        public ?string $title,
        public string $format,
        public ?string $output,
        public array $phpunitArguments,
    ) {}

    /**
     * @param  list<string>  $arguments  arguments without the script name
     *
     * @throws InvalidArgumentException
     */
    public static function parse(array $arguments, string $workingDirectory): self
    {
        $values = self::configuration($workingDirectory);
        $cliLists = [];
        $positional = [];
        $phpunitArguments = [];

        while ($arguments !== []) {
            $argument = array_shift($arguments);

            if ($argument === '--') {
                $phpunitArguments = $arguments;

                break;
            }

            if (!str_starts_with($argument, '--')) {
                $positional[] = $argument;

                continue;
            }

            $name = substr($argument, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (!in_array($name, [...self::SCALAR_OPTIONS, ...self::LIST_OPTIONS], true)) {
                throw new InvalidArgumentException(sprintf('Unknown option [--%s].', $name));
            }

            if ($value === null) {
                $value = array_shift($arguments);

                if ($value === null) {
                    throw new InvalidArgumentException(sprintf('Option [--%s] requires a value.', $name));
                }
            }

            if (in_array($name, self::LIST_OPTIONS, true)) {
                $cliLists[$name][] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        foreach ($cliLists as $name => $list) {
            $values[$name] = $list;
        }

        if (count($positional) !== 1) {
            throw new InvalidArgumentException('Expected exactly one test group.');
        }

        $vendor = self::stringValue($values, 'vendor') ?? 'copy';
        $format = self::stringValue($values, 'format') ?? 'markdown';

        if (!in_array($vendor, ['copy', 'install'], true)) {
            throw new InvalidArgumentException('Option [--vendor] must be "copy" or "install".');
        }

        if (!in_array($format, ['markdown', 'json'], true)) {
            throw new InvalidArgumentException('Option [--format] must be "markdown" or "json".');
        }

        return new self(
            group: $positional[0],
            baseline: self::stringValue($values, 'baseline'),
            vendorStrategy: $vendor,
            copyPaths: self::listValue($values, 'copy'),
            phpIni: self::listValue($values, 'php-ini'),
            phpunit: self::stringValue($values, 'phpunit') ?? 'vendor/bin/phpunit',
            rerunTemplate: self::stringValue($values, 'rerun') ?? RerunCommand::DEFAULT_TEMPLATE,
            ticketPrefix: self::stringValue($values, 'ticket-prefix'),
            ticketUrl: self::stringValue($values, 'ticket-url'),
            title: self::stringValue($values, 'title'),
            format: $format,
            output: self::outputPath(self::stringValue($values, 'output'), $positional[0], $workingDirectory),
            phpunitArguments: $phpunitArguments,
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    public static function isHelpRequest(array $arguments): bool
    {
        return in_array('--help', $arguments, true) || in_array('-h', $arguments, true);
    }

    /**
     * The ticket ID is the group without the configured prefix, if it has one.
     */
    public function ticketId(): ?string
    {
        if ($this->ticketPrefix === null || $this->ticketPrefix === '' || !str_starts_with($this->group, $this->ticketPrefix)) {
            return null;
        }

        $id = substr($this->group, strlen($this->ticketPrefix));

        return $id === '' ? null : $id;
    }

    public function ticketLink(): ?string
    {
        $id = $this->ticketId();

        if ($this->ticketUrl === null || $id === null) {
            return null;
        }

        return str_replace(['{id}', '{group}'], [rawurlencode($id), rawurlencode($this->group)], $this->ticketUrl);
    }

    /**
     * Resolves the report path: `-` means stdout, `{group}` becomes the group in filename-safe characters.
     */
    private static function outputPath(?string $template, string $group, string $workingDirectory): ?string
    {
        if ($template === null || $template === '-') {
            return null;
        }

        $path = str_replace('{group}', (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $group), $template);

        return str_starts_with($path, '/') ? $path : $workingDirectory . '/' . $path;
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuration(string $workingDirectory): array
    {
        $path = $workingDirectory . '/composer.json';

        if (!is_file($path)) {
            return [];
        }

        try {
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('Cannot read [%s]: %s', $path, $exception->getMessage()), previous: $exception);
        }

        $configuration = is_array($composer) && is_array($composer['extra'] ?? null) ? ($composer['extra']['test-evidence'] ?? []) : [];

        if (!is_array($configuration)) {
            throw new InvalidArgumentException('composer.json "extra.test-evidence" must be an object.');
        }

        $values = [];

        foreach ($configuration as $name => $value) {
            if (!is_string($name) || !in_array($name, self::CONFIGURABLE_OPTIONS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown composer.json "extra.test-evidence" key [%s].', (string) $name));
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function stringValue(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('Option [%s] must be a string.', $name));
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function listValue(array $values, string $name): array
    {
        $value = $values[$name] ?? [];

        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Option [%s] must be a string or a list of strings.', $name));
        }

        $list = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException(sprintf('Option [%s] must be a string or a list of strings.', $name));
            }

            $list[] = $item;
        }

        return $list;
    }
}
