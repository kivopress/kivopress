<?php

declare(strict_types=1);

$root = __DIR__;
$table_prefix = $table_prefix ?? 'kp_';

// Kivopress follows PHP-first config. Runtime config is not loaded
// from .env files; use constants here or kp-content/config.php overrides.
defined('KIVOPRESS_DEBUG') || define('KIVOPRESS_DEBUG', true);
defined('KIVOPRESS_TIMEZONE') || define('KIVOPRESS_TIMEZONE', 'UTC');
defined('KIVOPRESS_LOCAL_CONFIG') || define('KIVOPRESS_LOCAL_CONFIG', $root . '/kp-content/config.php');

defined('DB_CONNECTION') || define('DB_CONNECTION', 'file');
defined('DB_PATH') || define('DB_PATH', $root . '/kp-content/data/kivopress.sqlite');
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'kivopress');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASSWORD') || define('DB_PASSWORD', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

defined('KIVOPRESS_THEME') || define('KIVOPRESS_THEME', 'default');
defined('KIVOPRESS_API_CORS') || define('KIVOPRESS_API_CORS', true);
defined('KIVOPRESS_API_TOKEN_TTL_DAYS') || define('KIVOPRESS_API_TOKEN_TTL_DAYS', 90);
defined('KIVOPRESS_API_RATE_LIMIT') || define('KIVOPRESS_API_RATE_LIMIT', false);
defined('KIVOPRESS_API_RATE_LIMIT_MAX') || define('KIVOPRESS_API_RATE_LIMIT_MAX', 120);
defined('KIVOPRESS_API_RATE_LIMIT_WINDOW') || define('KIVOPRESS_API_RATE_LIMIT_WINDOW', 60);

$bool = static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOLEAN);
$localConfigPath = (string) KIVOPRESS_LOCAL_CONFIG;

$config = [
    'name' => 'Kivopress',
    'version' => \Kivopress\App::VERSION,
    'debug' => $bool(KIVOPRESS_DEBUG),
    'timezone' => (string) KIVOPRESS_TIMEZONE,
    'installed' => is_file($localConfigPath),
    'local_config_path' => $localConfigPath,
    'database' => [
        'driver' => (string) DB_CONNECTION,
        'path' => (string) DB_PATH,
        'host' => (string) DB_HOST,
        'port' => (string) DB_PORT,
        'name' => (string) DB_NAME,
        'user' => (string) DB_USER,
        'password' => (string) DB_PASSWORD,
        'charset' => (string) DB_CHARSET,
        'prefix' => (string) $table_prefix,
    ],
    'theme' => (string) KIVOPRESS_THEME,
    'api' => [
        'cors' => $bool(KIVOPRESS_API_CORS),
        'token_ttl_days' => (int) KIVOPRESS_API_TOKEN_TTL_DAYS,
        'rate_limit' => [
            'enabled' => $bool(KIVOPRESS_API_RATE_LIMIT),
            'max_attempts' => (int) KIVOPRESS_API_RATE_LIMIT_MAX,
            'window_seconds' => (int) KIVOPRESS_API_RATE_LIMIT_WINDOW,
        ],
    ],
];

if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    $config = array_replace_recursive($config, is_array($localConfig) ? $localConfig : []);
    $config['installed'] = true;
}

return $config;
