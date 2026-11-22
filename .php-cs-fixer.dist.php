<?php

declare(strict_types=1);

use ESoul\CodingStandard\Config;
use PhpCsFixer\Finder;

return Config::create(
    Finder::create()->files()->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/resources'])->append([__FILE__]),
    __DIR__ . '/cache/php-cs-fixer.cache',
)->setRules([
    ...Config::rules(),
    // require.php allows PHP < 8.4: `new Foo()->bar()` needs 8.4.
    'new_expression_parentheses' => ['use_parentheses' => true],
]);
