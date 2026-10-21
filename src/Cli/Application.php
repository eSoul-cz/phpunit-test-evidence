<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Cli;

use function array_map;
use function array_unique;
use function array_values;
use function bin2hex;

use DateTimeImmutable;

use function dirname;

use Esoul\TestEvidence\Extension;
use Esoul\TestEvidence\Report\Baseline;
use Esoul\TestEvidence\Report\JsonRenderer;
use Esoul\TestEvidence\Report\MarkdownRenderer;
use Esoul\TestEvidence\Report\Report;
use Esoul\TestEvidence\RunResult;
use Esoul\TestEvidence\TestRecord;

use function file_put_contents;
use function fwrite;

use InvalidArgumentException;

use function is_file;
use function random_bytes;

use RuntimeException;

use function sprintf;
use function sys_get_temp_dir;

use Throwable;

use function unlink;

/**
 * `test-evidence <group>`: runs the group, optionally runs it again against a baseline ref and
 * renders the report.
 *
 * Exit codes: 0 when every test of the group passes, 1 when a test does not pass, 2 when the
 * report cannot be produced.
 */
final readonly class Application
{
    private ProcessRunner $processes;

    /**
     * @var resource
     */
    private mixed $progress;

    /**
     * @param  resource|null  $progress  receives PHPUnit's console output and status messages (default: STDERR)
     */
    public function __construct(
        mixed $progress = null,
        private ?DateTimeImmutable $now = null,
    ) {
        $this->progress = $progress ?? STDERR;
        $this->processes = new ProcessRunner($this->progress);
    }

    /**
     * @param  list<string>  $arguments  arguments without the script name
     */
    public function run(array $arguments, string $workingDirectory): int
    {
        if (Options::isHelpRequest($arguments)) {
            fwrite(STDOUT, Options::USAGE . "\n");

            return 0;
        }

        try {
            $options = Options::parse($arguments, $workingDirectory);
        } catch (InvalidArgumentException $exception) {
            fwrite($this->progress, $exception->getMessage() . "\n\n" . Options::USAGE . "\n");

            return 2;
        }

        try {
            return $this->report($options, $workingDirectory);
        } catch (Throwable $exception) {
            fwrite($this->progress, sprintf("test-evidence: %s\n", $exception->getMessage()));

            return 2;
        }
    }

    private function report(Options $options, string $workingDirectory): int
    {
        $git = new Git($this->processes);
        $result = $this->runGroup($options, $workingDirectory, prependExtensionAutoloader: false);

        if ($result->count() === 0) {
            throw new RuntimeException(sprintf('No tests ran for group [%s].', $options->group));
        }

        $baseline = $options->baseline === null ? null : $this->runBaseline($options, $workingDirectory, $git, $result);
        $insideRepository = $this->insideRepository($git, $workingDirectory);

        $report = new Report(
            group: $options->group,
            result: $result,
            generatedAt: $this->now ?? new DateTimeImmutable(),
            rerunTemplate: $options->rerunTemplate,
            title: $options->title,
            ticketId: $options->ticketId(),
            ticketUrl: $options->ticketLink(),
            commit: $insideRepository ? $git->shortCommit($workingDirectory) : null,
            branch: $insideRepository ? $git->branch($workingDirectory) : null,
            hasUncommittedChanges: $insideRepository && $git->hasUncommittedChanges($workingDirectory),
            baseline: $baseline,
        );

        $rendered = $options->format === 'json' ? (new JsonRenderer())->render($report) : (new MarkdownRenderer())->render($report);

        if ($options->output === null) {
            fwrite(STDOUT, $rendered);
        } elseif (file_put_contents($options->output, $rendered) === false) {
            throw new RuntimeException(sprintf('Cannot write the report to [%s].', $options->output));
        }

        return $result->isSuccessful() ? 0 : 1;
    }

    private function runBaseline(Options $options, string $workingDirectory, Git $git, RunResult $result): Baseline
    {
        $testFiles = array_values(array_unique(array_map(static fn (TestRecord $record): string => $record->file, $result->records)));

        fwrite($this->progress, sprintf("test-evidence: preparing baseline %s\n", $options->baseline));

        $workspace = BaselineWorkspace::create(
            $git,
            $this->processes,
            $workingDirectory,
            (string) $options->baseline,
            $options->vendorStrategy,
            $testFiles,
            $options->copyPaths,
        );

        try {
            $baselineResult = $this->runGroup($options, $workspace->projectDirectory, prependExtensionAutoloader: true);
        } finally {
            $workspace->remove();
        }

        return new Baseline((string) $options->baseline, $git->shortCommit($workingDirectory, (string) $options->baseline), $baselineResult);
    }

    /**
     * The baseline may predate this package, so its extension is then loaded through a prepended
     * autoloader pointing at the running package instead of the baseline's vendor directory.
     */
    private function runGroup(Options $options, string $directory, bool $prependExtensionAutoloader): RunResult
    {
        $output = sys_get_temp_dir() . '/test-evidence-' . bin2hex(random_bytes(6)) . '.json';
        $command = [PHP_BINARY];

        foreach ($options->phpIni as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }

        if ($prependExtensionAutoloader) {
            $command[] = '-d';
            $command[] = 'auto_prepend_file=' . dirname(__DIR__, 2) . '/resources/autoload.php';
        }

        $command = [
            ...$command,
            $options->phpunit,
            '--group',
            $options->group,
            '--extension',
            Extension::class,
            ...$options->phpunitArguments,
        ];

        fwrite($this->progress, sprintf("test-evidence: running group %s in %s\n", $options->group, $directory));

        try {
            $this->processes->passthru($command, $directory, [Extension::OUTPUT_ENVIRONMENT_VARIABLE => $output]);

            if (!is_file($output)) {
                throw new RuntimeException('PHPUnit did not produce test evidence results. Did the run abort before executing tests?');
            }

            return RunResult::fromJsonFile($output);
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    private function insideRepository(Git $git, string $directory): bool
    {
        try {
            $git->root($directory);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
