<?php

declare(strict_types=1);

namespace Tests\Unit;

use Esoul\TestEvidence\Report\RerunCommand;
use Esoul\TestEvidence\TestOutcome;
use Esoul\TestEvidence\TestRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RerunCommandTest extends TestCase
{
    /**
     * @return array<string, array{int|string|null}>
     */
    public static function dataSetNames(): array
    {
        return [
            'no data set' => [null],
            'unnamed data set' => [3],
            'plain name' => ['503 Service Unavailable'],
            'regex metacharacters' => ['a.b*c+d?(e)[f]{g}|h^i$j/k'],
            'quotes' => ['it\'s "quoted"'],
            'colon, hash and dash' => ['a:b#c-d'],
            'backslash before colon' => ['path\\:x'],
        ];
    }

    #[DataProvider('dataSetNames')]
    public function test_filter_matches_exactly_the_recorded_test_name(int|string|null $dataSetName): void
    {
        $record = $this->record('test_adds', $dataSetName);
        $filter = RerunCommand::filter($record);

        self::assertSame(1, preg_match($filter, $record->id()), $filter);
        self::assertSame(0, preg_match($filter, $this->record('test_adds_twice', $dataSetName)->id()), 'A method with the same prefix must not match.');
        self::assertSame(0, preg_match($filter, 'Other\Tests\CalculatorTest::' . $record->nameWithDataSet()), 'A same-named class in another namespace must not match.');
        self::assertSame(0, preg_match($filter, $record->id() . ' extra'), 'The filter must be anchored at the end.');
    }

    public function test_command_quotes_the_filter_for_the_shell(): void
    {
        $command = RerunCommand::forRecord($this->record('test_adds', 'it\'s'), 'php artisan test --filter {filter}');

        self::assertSame(
            'php artisan test --filter \'/^App\\\\Tests\\\\CalculatorTest::test_adds with data set "it\'\\\'\'s"$/\'',
            $command,
        );
    }

    private function record(string $methodName, int|string|null $dataSetName): TestRecord
    {
        return new TestRecord(
            className: 'App\Tests\CalculatorTest',
            methodName: $methodName,
            file: '/project/tests/CalculatorTest.php',
            dataSetName: $dataSetName,
            testDox: 'Adds',
            groups: [],
            outcome: TestOutcome::Passed,
            durationSeconds: 0.0,
            assertions: 1,
        );
    }
}
