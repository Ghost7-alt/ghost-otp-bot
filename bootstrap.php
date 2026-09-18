<?php

declare(strict_types=1);

use GhostBot\Config;

spl_autoload_register(static function (string $class): void {
    $prefix = 'GhostBot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Config::loadEnv(__DIR__ . '/.env');
