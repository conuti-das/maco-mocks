<?php

declare(strict_types=1);

// Minimaler Autoloader für den Namespace MacoMocks (keine Composer-Abhängigkeiten).
spl_autoload_register(static function (string $class): void {
    $prefix = 'MacoMocks\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
