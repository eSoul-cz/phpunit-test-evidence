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

    public function test_baseline_reason_names_the_first_missing_and_unexpected_lines_of_a_structured_diff(): void
    {
        $arrayDiff = "Failed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n@@ @@\n     0 => '1 GET /product 200 succeeded',\n-    1 => '2 POST /assign-hub-spot-id 204 succeeded',\n ]";
        $replacedDiff = "Failed asserting that two strings are identical.\n--- Expected\n+++ Actual\n@@ @@\n first\n-second\n+other\n";
        $scalarDiff = "Failed asserting that -1 is identical to 3.\n--- Expected\n+++ Actual\n@@ @@\n-3\n+-1";
        $current = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_missing', TestOutcome::Passed),
            $this->record('test_replaced', TestOutcome::Passed),
            $this->record('test_scalar', TestOutcome::Passed),
        ]);
        $baseline = new RunResult('8.5.0', '12.5.0', [
            $this->record('test_missing', TestOutcome::Failed, 'Failed asserting that two arrays are identical.', $arrayDiff),
            $this->record('test_replaced', TestOutcome::Failed, 'Failed asserting that two strings are identical.', $replacedDiff),
            $this->record('test_scalar', TestOutcome::Failed, 'Failed asserting that -1 is identical to 3.', $scalarDiff),
        ]);

        $markdown = (new MarkdownRenderer())->render($this->report($current, new Baseline('6f98972', '6f98972', $baseline)));

        self::assertStringContainsString("| 1 | test_missing | ❌ failed | Failed asserting that two arrays are identical. Expected `1 => '2 POST /assign-hub-spot-id 204 succeeded'`, missing. |", $markdown);
        self::assertStringContainsString('| 2 | test_replaced | ❌ failed | Failed asserting that two strings are identical. Expected `second`, got `other`. |', $markdown);
        self::assertStringContainsString('| 3 | test_scalar | ❌ failed | Failed asserting that -1 is identical to 3. |', $markdown);
        self::assertStringContainsString('| Without the change | `6f98972`: **3 of 3 tests do not pass** |', $markdown);
        self::assertStringContainsString('the application code of `6f98972`;', $markdown);
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
