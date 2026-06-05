<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Analyzer.php';
require_once __DIR__ . '/src/SitemapService.php';
require_once __DIR__ . '/src/Frontend.php';
require_once __DIR__ . '/src/MetaBox.php';
require_once __DIR__ . '/src/Admin/SettingsPage.php';
require_once __DIR__ . '/src/Admin/SitemapPage.php';
require_once __DIR__ . '/src/Plugin.php';

(new \KivopressSeo\Plugin())->boot();
