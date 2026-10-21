<?php

declare(strict_types=1);

namespace Esoul\TestEvidence\Report;

use function array_slice;
use function count;

use Esoul\TestEvidence\RunResult;
use Esoul\TestEvidence\TestOutcome;
use Esoul\TestEvidence\TestRecord;

use function explode;
use function implode;
use function is_int;
use function number_format;
use function sprintf;
use function str_replace;
use function trim;

final class MarkdownRenderer
{
    private const int DETAIL_LINE_LIMIT = 40;

    public function render(Report $report): string
    {
        $sections = [
            $this->header($report),
            $this->results($report->result),
            $this->rerunCommands($report),
        ];

        if ($report->baseline !== null) {
            $sections[] = $this->baseline($report->result, $report->baseline);
        }

        $failures = $this->failures($report->result);

        if ($failures !== null) {
            $sections[] = $failures;
        }

        return implode("\n\n", $sections) . "\n";
    }

    private function header(Report $report): string
    {
        $result = $report->result;
        $title = $report->title ?? sprintf('Test evidence: %s', $report->group);

        $rows = [];

        if ($report->ticketId !== null) {
            $rows[] = ['Ticket', $report->ticketUrl !== null ? sprintf('[%s](%s)', $report->ticketId, $report->ticketUrl) : $report->ticketId];
        }

        $rows[] = ['Test group', $this->code($report->group)];

        if ($report->commit !== null) {
            $commit = $this->code($report->commit);

            if ($report->branch !== null) {
                $commit .= ' on ' . $this->code($report->branch);
            }

            if ($report->hasUncommittedChanges) {
                $commit .= ' **with uncommitted changes**';
            }

            $rows[] = ['Commit', $commit];
        }

        $rows[] = ['Generated', $report->generatedAt->format('Y-m-d H:i:s P')];
        $rows[] = ['Runtime', sprintf('PHP %s, PHPUnit %s', $result->phpVersion, $result->phpunitVersion)];
        $rows[] = ['Result', sprintf(
            '**%d of %d %s passed** (%d assertions)',
            $result->successfulCount(),
            $result->count(),
            $result->count() === 1 ? 'test' : 'tests',
            $result->assertions(),
        )];

        if ($report->baseline !== null) {
            $rows[] = ['Without the change', sprintf(
                '%s: **%d of %d tests do not pass**',
                $this->baselineLabel($report->baseline, ' at '),
                $this->notPassingAtBaseline($result, $report->baseline->result),
                $result->count(),
            )];
        }

        return '# ' . $title . "\n\n" . $this->table(['', ''], $rows);
    }

    private function results(RunResult $result): string
    {
        $rows = [];

        foreach ($result->records as $index => $record) {
            $rows[] = [
                (string) ($index + 1),
                $record->testDox,
                $this->testName($record),
                $this->outcome($record->outcome),
                $this->duration($record->durationSeconds),
            ];
        }

        return "## Results\n\n" . $this->table(['#', 'Behaviour', 'Test', 'Result', 'Time'], $rows);
    }

    private function rerunCommands(Report $report): string
    {
        $lines = [];

        foreach ($report->result->records as $index => $record) {
            $lines[] = sprintf('# %d. %s', $index + 1, $this->singleLine($record->testDox));
            $lines[] = RerunCommand::forRecord($record, $report->rerunTemplate);
        }

        return "## Re-run a single test\n\n```sh\n" . implode("\n", $lines) . "\n```";
    }

    private function baseline(RunResult $result, Baseline $baseline): string
    {
        $rows = [];

        foreach ($result->records as $index => $record) {
            $baselineRecord = $baseline->result->find($record->id());

            $rows[] = [
                (string) ($index + 1),
                $record->testDox,
                $baselineRecord === null ? 'not run' : $this->outcome($baselineRecord->outcome),
                $baselineRecord === null ? '' : $this->reason($baselineRecord),
            ];
        }

        return sprintf(
            "## Without the change: %s\n\nThe same tests were run against the application code of %s; the test files were taken from the evaluated commit. A test that does not pass here shows the behaviour that the change introduces or fixes.\n\n%s",
            $this->code($baseline->ref),
            $this->baselineLabel($baseline, ' '),
            $this->table(['#', 'Behaviour', 'Result', 'Reason'], $rows),
        );
    }

    private function failures(RunResult $result): ?string
    {
        $sections = [];

        foreach ($result->records as $index => $record) {
            if ($record->outcome->isSuccessful()) {
                continue;
            }

            $details = $record->details ?? $record->message ?? '';
            $lines = explode("\n", trim($details));

            if (count($lines) > self::DETAIL_LINE_LIMIT) {
                $lines = [...array_slice($lines, 0, self::DETAIL_LINE_LIMIT), '…'];
            }

            $sections[] = sprintf(
                "### %d. %s\n\n%s, %s\n\n```text\n%s\n```",
                $index + 1,
                $this->singleLine($record->testDox),
                $this->code($record->id()),
                $this->outcome($record->outcome),
                implode("\n", $lines),
            );
        }

        return $sections === [] ? null : "## Failures\n\n" . implode("\n\n", $sections);
    }

    private function notPassingAtBaseline(RunResult $result, RunResult $baseline): int
    {
        $count = 0;

        foreach ($result->records as $record) {
            if (!$baseline->find($record->id())?->outcome->isSuccessful()) {
                $count++;
            }
        }

        return $count;
    }

    private function testName(TestRecord $record): string
    {
        $name = $this->code($record->shortClassName() . '::' . $record->methodName);

        if ($record->dataSetName === null) {
            return $name;
        }

        return $name . '<br>data set ' . $this->code(is_int($record->dataSetName) ? '#' . $record->dataSetName : $record->dataSetName);
    }

    private function outcome(TestOutcome $outcome): string
    {
        return match ($outcome) {
            TestOutcome::Passed => '✅ ' . $outcome->label(),
            TestOutcome::Failed, TestOutcome::Errored => '❌ ' . $outcome->label(),
            TestOutcome::Skipped, TestOutcome::Incomplete => '⚠️ ' . $outcome->label(),
        };
    }

    private function duration(float $seconds): string
    {
        return $seconds < 1.0
            ? sprintf('%d ms', (int) round($seconds * 1000))
            : number_format($seconds, 2) . ' s';
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function table(array $header, array $rows): string
    {
        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '|' . implode('|', array_fill(0, count($header), '---')) . '|',
        ];

        foreach ($rows as $row) {
            $lines[] = '| ' . implode(' | ', array_map($this->cell(...), $row)) . ' |';
        }

        return implode("\n", $lines);
    }

    private function cell(string $value): string
    {
        return str_replace('|', '\|', $this->singleLine($value));
    }

    private function singleLine(string $value): string
    {
        return trim(str_replace(["\r\n", "\n", "\r"], ' ', $value));
    }

    private function firstLine(string $value): string
    {
        return explode("\n", trim($value))[0];
    }

    /**
     * The failure message plus, for a diff of a structured value (arrays, multi-line strings), the
     * first expected line that is missing and the first actual line that is unexpected, which
     * usually name the behaviour that differs. Scalar diffs only repeat the message.
     */
    private function reason(TestRecord $record): string
    {
        if ($record->message === null) {
            return '';
        }

        $reason = $this->singleLine($this->firstLine($record->message));
        $missing = null;
        $unexpected = null;
        $hasContext = false;
        $inDiff = false;

        foreach (explode("\n", $record->details ?? '') as $line) {
            if (str_starts_with($line, '@@')) {
                $inDiff = true;
            } elseif (!$inDiff || str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                continue;
            } elseif ($missing === null && str_starts_with($line, '-')) {
                $missing = $this->diffLine($line);
            } elseif ($unexpected === null && str_starts_with($line, '+')) {
                $unexpected = $this->diffLine($line);
            } elseif (str_starts_with($line, ' ')) {
                $hasContext = true;
            }
        }

        if (!$hasContext) {
            return $reason;
        }

        if ($missing !== null) {
            $reason .= ' Expected ' . $this->code($missing) . ($unexpected === null ? ', missing.' : ',');
        }

        if ($unexpected !== null) {
            $reason .= ($missing === null ? ' Unexpected ' : ' got ') . $this->code($unexpected) . '.';
        }

        return $reason;
    }

    private function diffLine(string $line): string
    {
        $line = rtrim(trim(substr($line, 1)), ',');

        return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
    }

    /**
     * "main at 57ebb7e", or just the commit when the ref already is it.
     */
    private function baselineLabel(Baseline $baseline, string $separator): string
    {
        if ($baseline->commit === null || str_starts_with($baseline->commit, $baseline->ref) || str_starts_with($baseline->ref, $baseline->commit)) {
            return $this->code($baseline->ref);
        }

        return $this->code($baseline->ref) . $separator . ($separator === ' ' ? '(' . $this->code($baseline->commit) . ')' : $this->code($baseline->commit));
    }

    private function code(string $value): string
    {
        return '`' . str_replace('`', "'", $value) . '`';
    }
}
