<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Esoul\TestEvidence\Report\Baseline;
use Esoul\TestEvidence\Report\MarkdownRenderer;
use Esoul\TestEvidence\Report\Report;
use Esoul\TestEvidence\RunResult;
use Esoul\TestEvidence\TestOutcome;
use Esoul\TestEvidence\TestRecord;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function test_test_missing_from_the_baseline_run_is_reported_as_not_run_and_counted(): void
    {
        $current = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_existing', TestOutcome::Passed),
            $this->record('test_new', TestOutcome::Passed),
        ]);
        $baseline = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_existing', TestOutcome::Passed),
        ]);

        $markdown = (new MarkdownRenderer())->render($this->report($current, new Baseline('main', 'abc1234', $baseline)));

        self::assertStringContainsString('| Without the change | `main` at `abc1234`: **1 of 2 tests do not pass** |', $markdown);
        self::assertStringContainsString('| 2 | test_new | not run |  |', $markdown);
    }

    public function test_failure_details_are_truncated_and_messages_stay_on_one_table_line(): void
    {
        $details = implode("\n", array_map(static fn (int $line): string => 'line ' . $line, range(1, 50)));
        $current = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_broken', TestOutcome::Failed, "First | line\nsecond line", $details),
        ]);
        $baseline = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_broken', TestOutcome::Failed, "First | line\nsecond line"),
        ]);

        $markdown = (new MarkdownRenderer())->render($this->report($current, new Baseline('main', null, $baseline)));

        self::assertStringContainsString('| 1 | test_broken | ❌ failed | First \| line |', $markdown);
        self::assertStringContainsString("line 40\n…\n```", $markdown);
        self::assertStringNotContainsString('line 41', $markdown);
        self::assertStringContainsString('**0 of 1 test passed**', $markdown);
    }

    public function test_uncommitted_changes_are_flagged(): void
    {
        $current = new RunResult('8.5.0', '12.5.0', [$this->record('test_existing', TestOutcome::Passed)]);

        $markdown = (new MarkdownRenderer())->render(new Report(
            group: 'ticket-1',
            result: $current,
            generatedAt: new DateTimeImmutable('2026-10-21 12:00:00+00:00'),
            rerunTemplate: 'vendor/bin/phpunit --filter {filter}',
            commit: 'abc1234',
            branch: 'main',
            hasUncommittedChanges: true,
        ));

        self::assertStringContainsString('| Commit | `abc1234` on `main` **with uncommitted changes** |', $markdown);
    }

    private function report(RunResult $result, ?Baseline $baseline): Report
    {
        return new Report(
            group: 'ticket-1',
            result: $result,
            generatedAt: new DateTimeImmutable('2026-10-21 12:00:00+00:00'),
            rerunTemplate: 'vendor/bin/phpunit --filter {filter}',
            baseline: $baseline,
        );
    }

    private function record(string $methodName, TestOutcome $outcome, ?string $message = null, ?string $details = null): TestRecord
    {
        return new TestRecord(
            className: 'App\Tests\ExampleTest',
            methodName: $methodName,
            file: '/project/tests/ExampleTest.php',
            dataSetName: null,
            testDox: $methodName,
            groups: ['ticket-1'],
            outcome: $outcome,
            durationSeconds: 0.01,
            assertions: 1,
            message: $message,
            details: $details,
        );
    }
}
