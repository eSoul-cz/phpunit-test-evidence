<?php

declare(strict_types=1);

/*
 * Prepended to the baseline PHPUnit process so the extension loads from the running package,
 * even when the baseline commit's vendor directory does not contain this package.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Esoul\\TestEvidence\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);
