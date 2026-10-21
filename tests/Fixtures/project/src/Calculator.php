<?php

declare(strict_types=1);

namespace FixtureProject;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
