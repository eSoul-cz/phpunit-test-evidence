<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'FixtureProject\\';

    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = str_starts_with($relative, 'Tests/')
            ? __DIR__ . '/tests/' . substr($relative, 6) . '.php'
            : __DIR__ . '/src/' . $relative . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});
