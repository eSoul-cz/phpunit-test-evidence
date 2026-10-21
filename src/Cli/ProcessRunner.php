<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Cli;

use function getenv;
use function implode;
use function is_resource;
use function proc_close;
use function proc_open;

use RuntimeException;

use function sprintf;
use function stream_get_contents;
use function trim;

/**
 * Runs external commands without a shell.
 *
 * @internal
 */
final readonly class ProcessRunner
{
    /**
     * @var resource
     */
    private mixed $progress;

    /**
     * @param  resource|null  $progress  receives the output of passthru commands (default: STDERR)
     */
    public function __construct(mixed $progress = null)
    {
        $this->progress = $progress ?? STDERR;
    }

    /**
     * Runs a command with its output forwarded to the progress stream, so STDOUT stays free for the report.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment  added to the current environment
     */
    public function passthru(array $command, string $workingDirectory, array $environment = []): int
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => $this->progress, 2 => $this->progress],
            $pipes,
            $workingDirectory,
            [...getenv(), ...$environment],
        );

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot start [%s].', implode(' ', $command)));
        }

        return proc_close($process);
    }

    /**
     * Runs a command and returns its trimmed standard output.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException when the command fails
     */
    public function capture(array $command, string $workingDirectory): string
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Cannot start [%s].', implode(' ', $command)));
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf('[%s] failed with exit code %d: %s', implode(' ', $command), $exitCode, trim($error)));
        }

        return trim($output);
    }
}
