<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Collector;

use function dirname;

use Esoul\TestEvidence\RunResult;
use Esoul\TestEvidence\TestOutcome;
use Esoul\TestEvidence\TestRecord;

use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\Throwable;
use PHPUnit\Event\Telemetry\HRTime;
use PHPUnit\Metadata\Group;
use PHPUnit\Runner\Version;
use RuntimeException;

use function sprintf;
use function trim;

/**
 * Collects the outcome of every executed test method and writes them as JSON when the run ends.
 *
 * @internal
 */
final class ResultCollector
{
    /**
     * @var array<string, array{test: TestMethod, started: ?HRTime, outcome: TestOutcome, message: ?string, details: ?string}>
     */
    private array $pending = [];

    /**
     * @var list<TestRecord>
     */
    private array $records = [];

    public function __construct(
        private readonly string $outputPath,
    ) {}

    public function prepared(Test $test, HRTime $time): void
    {
        if (!$test instanceof TestMethod) {
            return;
        }

        $this->pending[$test->id()] = [
            'test' => $test,
            'started' => $time,
            'outcome' => TestOutcome::Passed,
            'message' => null,
            'details' => null,
        ];
    }

    public function failed(Test $test, Throwable $throwable, ?string $diff): void
    {
        $details = trim($throwable->description() . ($diff !== null && $diff !== '' ? "\n" . $diff : ''));

        $this->outcome($test, TestOutcome::Failed, $throwable->message(), $details);
    }

    public function errored(Test $test, Throwable $throwable): void
    {
        $this->outcome(
            $test,
            TestOutcome::Errored,
            sprintf('%s: %s', $throwable->className(), $throwable->message()),
            $throwable->asString(),
        );
    }

    public function skipped(Test $test, string $message): void
    {
        $this->outcome($test, TestOutcome::Skipped, $message, null);
    }

    public function incomplete(Test $test, Throwable $throwable): void
    {
        $this->outcome($test, TestOutcome::Incomplete, $throwable->message(), null);
    }

    public function finished(Test $test, HRTime $time, int $assertions): void
    {
        if (!$test instanceof TestMethod || !isset($this->pending[$test->id()])) {
            return;
        }

        $this->record($test->id(), $time, $assertions);
    }

    /**
     * Records tests that never reached the finished event (e.g. an error before the test ran) and
     * writes all records to the output file.
     */
    public function executionFinished(): void
    {
        foreach ($this->pending as $id => $pending) {
            $this->record($id, null, 0);
        }

        $directory = dirname($this->outputPath);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create directory [%s] for test evidence results.', $directory));
        }

        $result = new RunResult(PHP_VERSION, Version::id(), $this->records);

        if (file_put_contents($this->outputPath, json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException(sprintf('Cannot write test evidence results to [%s].', $this->outputPath));
        }
    }

    private function outcome(Test $test, TestOutcome $outcome, string $message, ?string $details): void
    {
        if (!$test instanceof TestMethod) {
            return;
        }

        $this->pending[$test->id()] ??= [
            'test' => $test,
            'started' => null,
            'outcome' => TestOutcome::Passed,
            'message' => null,
            'details' => null,
        ];

        // The first non-passing outcome wins; a later teardown error must not hide the original failure.
        if ($this->pending[$test->id()]['outcome'] !== TestOutcome::Passed) {
            return;
        }

        $this->pending[$test->id()]['outcome'] = $outcome;
        $this->pending[$test->id()]['message'] = $message;
        $this->pending[$test->id()]['details'] = $details;
    }

    private function record(string $id, ?HRTime $finishedAt, int $assertions): void
    {
        $pending = $this->pending[$id];
        $test = $pending['test'];
        unset($this->pending[$id]);

        $groups = [];

        foreach ($test->metadata()->isGroup() as $metadata) {
            if ($metadata instanceof Group) {
                $groups[] = $metadata->groupName();
            }
        }

        $this->records[] = new TestRecord(
            className: $test->className(),
            methodName: $test->methodName(),
            file: $test->file(),
            dataSetName: $test->testData()->hasDataFromDataProvider() ? $test->testData()->dataFromDataProvider()->dataSetName() : null,
            testDox: $test->testDox()->prettifiedMethodName(),
            groups: $groups,
            outcome: $pending['outcome'],
            durationSeconds: $finishedAt !== null && $pending['started'] !== null ? $finishedAt->duration($pending['started'])->asFloat() : 0.0,
            assertions: $assertions,
            message: $pending['message'],
            details: $pending['details'],
        );
    }
}
