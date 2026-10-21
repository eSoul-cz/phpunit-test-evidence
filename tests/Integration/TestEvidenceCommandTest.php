<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Esoul\TestEvidence\Cli\Application;

use function exec;
use function file_get_contents;
use function file_put_contents;

use FilesystemIterator;

use function implode;
use function is_string;
use function json_decode;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function sprintf;

/**
 * Runs the real CLI against a copy of tests/Fixtures/project, whose vendor directory is the
 * package's own.
 */
final class TestEvidenceCommandTest extends TestCase
{
    private string $project;

    /**
     * @var resource
     */
    private mixed $progress;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/test-evidence-fixture-' . bin2hex(random_bytes(6));
        $this->copyDirectory(dirname(__DIR__) . '/Fixtures/project', $this->project);
        symlink(dirname(__DIR__, 2) . '/vendor', $this->project . '/vendor');

        $progress = tmpfile();
        self::assertNotFalse($progress);
        $this->progress = $progress;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->project);
    }

    public function test_markdown_report_lists_only_the_group_with_resolved_testdox_and_ticket_link(): void
    {
        [$exitCode, $markdown] = $this->runCommand(['ticket-42']);

        self::assertSame(0, $exitCode, $this->progressOutput());
        self::assertStringStartsWith("# Test evidence: ticket-42\n", $markdown);
        self::assertStringContainsString('| Ticket | [42](https://tickets.example/42) |', $markdown);
        self::assertStringContainsString('| Result | **5 of 5 tests passed** (5 assertions) |', $markdown);
        self::assertStringContainsString('| 1 | Adds 1 and 2 to 3 | `CalculatorTest::test_adds`<br>data set `one plus two` | ✅ passed |', $markdown);
        self::assertStringContainsString('| Adds 0 and 0 to 0 |', $markdown);
        self::assertStringContainsString('data set `#1`', $markdown);
        self::assertStringContainsString('Returns an integer for "quoted" \| piped input', $markdown);
        self::assertStringNotContainsString('test_unrelated', $markdown);
        self::assertStringNotContainsString('test_fails', $markdown);
        self::assertStringNotContainsString('## Failures', $markdown);
        self::assertStringNotContainsString('Without the change', $markdown);
    }

    public function test_every_rerun_command_runs_exactly_its_own_test(): void
    {
        [$exitCode, $json] = $this->runCommand(['ticket-42', '--format=json']);

        self::assertSame(0, $exitCode, $this->progressOutput());

        $records = $this->decode($json)['records'];
        self::assertIsArray($records);
        self::assertCount(5, $records);

        foreach ($records as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['rerun']);
            self::assertIsString($record['id']);

            $output = [];
            exec(sprintf('cd %s && %s 2>&1', escapeshellarg($this->project), $record['rerun']), $output, $rerunExitCode);
            $output = implode("\n", $output);

            self::assertSame(0, $rerunExitCode, $output);
            self::assertMatchesRegularExpression('/OK \(1 test, /', $output, sprintf('[%s] did not run exactly one test.', $record['id']));
        }
    }

    public function test_baseline_shows_which_tests_do_not_pass_without_the_change(): void
    {
        $calculator = $this->project . '/src/Calculator.php';
        $fixed = (string) file_get_contents($calculator);
        $tests = (string) file_get_contents($this->project . '/tests/CalculatorTest.php');

        unlink($this->project . '/tests/CalculatorTest.php');
        file_put_contents($calculator, str_replace('$a + $b', '$a - $b', $fixed));
        $this->git('init', '--quiet', '--initial-branch=main');
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--message=buggy');
        $buggyCommit = $this->git('rev-parse', '--short', 'HEAD');

        file_put_contents($calculator, $fixed);
        file_put_contents($this->project . '/tests/CalculatorTest.php', $tests);
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--message=fix');

        [$exitCode, $json] = $this->runCommand(['ticket-42', '--baseline=HEAD~1', '--format=json']);

        self::assertSame(0, $exitCode, $this->progressOutput());

        $report = $this->decode($json);
        self::assertSame('main', $report['branch']);
        self::assertIsArray($report['baseline']);
        self::assertSame($buggyCommit, $report['baseline']['commit']);
        self::assertSame([
            'FixtureProject\Tests\CalculatorTest::test_adds with data set "one plus two"' => 'failed',
            'FixtureProject\Tests\CalculatorTest::test_adds with data set "zero plus zero"' => 'passed',
            'FixtureProject\Tests\CalculatorTest::test_adds_twice with data set #0' => 'failed',
            'FixtureProject\Tests\CalculatorTest::test_adds_twice with data set #1' => 'failed',
            'FixtureProject\Tests\CalculatorTest::test_returns_integer' => 'passed',
        ], $this->outcomes($report['baseline']));
        self::assertSame(1, substr_count($this->git('worktree', 'list'), "\n") + 1, 'The baseline worktree must be removed.');

        [, $markdown] = $this->runCommand(['ticket-42', '--baseline=HEAD~1']);

        self::assertStringContainsString(sprintf('| Without the change | `HEAD~1` at `%s`: **3 of 5 tests do not pass** |', $buggyCommit), $markdown);
        self::assertStringContainsString('| 1 | Adds 1 and 2 to 3 | ❌ failed | Failed asserting that -1 is identical to 3. |', $markdown);
    }

    public function test_failing_group_exits_with_one_and_reports_the_failures(): void
    {
        [$exitCode, $markdown] = $this->runCommand(['broken']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('| Result | **0 of 3 tests passed**', $markdown);
        self::assertStringContainsString('| Fails on purpose | `CalculatorTest::test_fails` | ❌ failed |', $markdown);
        self::assertStringContainsString('❌ error', $markdown);
        self::assertStringContainsString('⚠️ skipped', $markdown);
        self::assertStringContainsString("## Failures\n", $markdown);
        self::assertStringContainsString('Failed asserting that 2 is identical to 1.', $markdown);
        self::assertStringContainsString('RuntimeException: Boom', $markdown);
        self::assertStringContainsString('Not today', $markdown);
    }

    public function test_group_without_tests_exits_with_two(): void
    {
        [$exitCode, $output] = $this->runCommand(['no-such-group']);

        self::assertSame(2, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('No tests ran for group [no-such-group].', $this->progressOutput());
    }

    /**
     * @param  list<string>  $arguments
     * @return array{int, string}
     */
    private function runCommand(array $arguments): array
    {
        $output = $this->project . '/report-' . bin2hex(random_bytes(4));
        $application = new Application($this->progress, new DateTimeImmutable('2026-10-21 12:00:00+02:00'));

        $exitCode = $application->run([...$arguments, '--output=' . $output], $this->project);

        $report = is_file($output) ? (string) file_get_contents($output) : '';

        if (is_file($output)) {
            unlink($output);
        }

        return [$exitCode, $report];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function outcomes(mixed $run): array
    {
        self::assertIsArray($run);
        self::assertIsArray($run['records']);
        $outcomes = [];

        foreach ($run['records'] as $record) {
            self::assertIsArray($record);
            self::assertIsString($record['className']);
            self::assertIsString($record['methodName']);
            self::assertIsString($record['outcome']);

            $dataSet = $record['dataSetName'];
            $name = $record['className'] . '::' . $record['methodName'];
            $name .= match (true) {
                is_int($dataSet) => ' with data set #' . $dataSet,
                is_string($dataSet) => ' with data set "' . $dataSet . '"',
                default => '',
            };
            $outcomes[$name] = $record['outcome'];
        }

        ksort($outcomes);

        return $outcomes;
    }

    private function git(string ...$arguments): string
    {
        $command = ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', '-c', 'commit.gpgsign=false', ...$arguments];
        $output = [];
        exec(sprintf('cd %s && %s 2>&1', escapeshellarg($this->project), implode(' ', array_map(escapeshellarg(...), $command))), $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));

        return trim(implode("\n", $output));
    }

    private function progressOutput(): string
    {
        rewind($this->progress);

        return (string) stream_get_contents($this->progress);
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0o777, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $destination = $target . substr($item->getPathname(), strlen($source));
            $item->isDir() ? mkdir($destination) : copy($item->getPathname(), $destination);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
