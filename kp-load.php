<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $adminMap = [
        'Kivopress\\Admin' => __DIR__ . '/kp-admin/Admin.php',
        'Kivopress\\AdminBar' => __DIR__ . '/kp-admin/includes/AdminBar.php',
    ];

    if (isset($adminMap[$class]) && is_file($adminMap[$class])) {
        require $adminMap[$class];

        return;
    }

    $adminPrefix = 'Kivopress\\Admin\\';

    if (str_starts_with($class, $adminPrefix)) {
        $path = __DIR__ . '/kp-admin/includes/' . str_replace('\\', '/', substr($class, strlen($adminPrefix))) . '.php';

        if (is_file($path)) {
            require $path;
        }

        return;
    }

    $prefix = 'Kivopress\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/kp-includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/kp-includes/helpers.php';

$config = require __DIR__ . '/kp-config.php';

$GLOBALS['kivopress'] = new Kivopress\App(__DIR__, $config);
$GLOBALS['kivopress']->boot();

return $GLOBALS['kivopress'];
