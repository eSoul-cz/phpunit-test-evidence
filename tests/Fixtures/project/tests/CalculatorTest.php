<?php

declare(strict_types=1);

namespace FixtureProject\Tests;

use FixtureProject\Calculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CalculatorTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int}>
     */
    public static function sums(): array
    {
        return [
            'one plus two' => [1, 2, 3],
            'zero plus zero' => [0, 0, 0],
        ];
    }

    /**
     * @return list<array{int}>
     */
    public static function doubles(): array
    {
        return [[1], [2]];
    }

    #[Group('ticket-42')]
    #[DataProvider('sums')]
    #[TestDox('Adds $a and $b to $expected')]
    public function test_adds(int $a, int $b, int $expected): void
    {
        self::assertSame($expected, (new Calculator())->add($a, $b));
    }

    #[Group('ticket-42')]
    #[DataProvider('doubles')]
    public function test_adds_twice(int $a): void
    {
        self::assertSame(2 * $a, (new Calculator())->add($a, $a));
    }

    #[Group('ticket-42')]
    #[TestDox('Returns an integer for "quoted" | piped input')]
    public function test_returns_integer(): void
    {
        self::assertIsInt((new Calculator())->add(1, 1));
    }

    #[Group('unrelated')]
    public function test_unrelated(): void
    {
        self::assertTrue(true);
    }

    #[Group('broken')]
    #[TestDox('Fails on purpose')]
    public function test_fails(): void
    {
        self::assertSame(1, 2);
    }

    #[Group('broken')]
    public function test_errors(): void
    {
        throw new RuntimeException('Boom');
    }

    #[Group('broken')]
    public function test_skipped(): void
    {
        self::markTestSkipped('Not today');
    }
}
