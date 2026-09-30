<?php

declare(strict_types=1);

namespace MacoMocks;

final class Config
{
    /** config.php (Standard) + config.local.php (nur auf dem Server, nicht im Repo). */
    public static function load(string $proxyDir): array
    {
        $config = require $proxyDir . '/config.php';
        if (is_file($proxyDir . '/config.local.php')) {
            $config = array_replace($config, require $proxyDir . '/config.local.php');
        }
        return $config;
    }
}
