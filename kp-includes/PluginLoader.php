<?php

declare(strict_types=1);

namespace Kivopress;

final class PluginLoader
{
    private array $metadata = [];
    private array $activationHooks = [];
    private array $deactivationHooks = [];

    public function __construct(private App $app, private string $rootPath)
    {
    }

    public function load(): void
    {
        $pluginsPath = $this->rootPath . '/kp-content/plugins';

        if (!is_dir($pluginsPath)) {
            return;
        }

        foreach ($this->activeSlugs() as $slug) {
            $pluginFile = $pluginsPath . '/' . $slug . '/plugin.php';

            if (is_file($pluginFile)) {
                $this->loadPlugin($pluginFile);
            }
        }

        do_action('plugins.loaded', $this->metadata);
    }

    public function registerMetadata(array $metadata): void
    {
        $slug = $metadata['slug'] ?? $this->slugFromTrace();
        $this->metadata[$slug] = $metadata;
    }

    public function registerActivationHook(string $file, callable $callback): void
    {
        $this->activationHooks[$this->slugFromFile($file)][] = $callback;
    }

    public function registerDeactivationHook(string $file, callable $callback): void
    {
        $this->deactivationHooks[$this->slugFromFile($file)][] = $callback;
    }

    public function all(): array
    {
        return $this->metadata;
    }

    public function discover(): array
    {
        $pluginsPath = $this->rootPath . '/kp-content/plugins';
        $active = $this->activeSlugs();
        $plugins = [];

        foreach (glob($pluginsPath . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            $manifest = $this->manifest($path);
            $metadata = array_merge($manifest, $this->metadata[$slug] ?? []);

            $plugins[] = [
                'slug' => $slug,
                'name' => $metadata['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug)),
                'description' => $metadata['description'] ?? 'A Kivopress plugin.',
                'version' => $metadata['version'] ?? '1.0.0',
                'author' => $metadata['author'] ?? 'Unknown',
                'active' => in_array($slug, $active, true),
                'valid' => is_file($path . '/plugin.php'),
            ];
        }

        usort($plugins, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $plugins;
    }

    public function activate(string $slug): void
    {
        $this->assertValid($slug);
        $active = $this->activeSlugs();

        if (!in_array($slug, $active, true)) {
            $active[] = $slug;
        }

        $this->app->db()->setOption('active_plugins', array_values($active));
        $this->loadPlugin($this->rootPath . '/kp-content/plugins/' . $slug . '/plugin.php');
        $this->runCallbacks($this->activationHooks[$slug] ?? [], $slug);
        do_action('plugin.activated', $slug);
    }

    public function deactivate(string $slug): void
    {
        $this->assertValid($slug);
        $pluginFile = $this->rootPath . '/kp-content/plugins/' . $slug . '/plugin.php';

        if (is_file($pluginFile) && empty($this->deactivationHooks[$slug])) {
            $this->loadPlugin($pluginFile);
        }

        $this->runCallbacks($this->deactivationHooks[$slug] ?? [], $slug);
        do_action('plugin.deactivated', $slug);
        $active = array_values(array_filter(
            $this->activeSlugs(),
            fn (string $activeSlug): bool => $activeSlug !== $slug
        ));

        $this->app->db()->setOption('active_plugins', $active);
    }

    private function loadPlugin(string $pluginFile): void
    {
        $app = $this->app;

        (static function () use ($pluginFile, $app): void {
            $previous = $GLOBALS['kivopress'] ?? null;
            $GLOBALS['kivopress'] = $app;

            try {
                require $pluginFile;
            } finally {
                if ($previous) {
                    $GLOBALS['kivopress'] = $previous;
                } else {
                    unset($GLOBALS['kivopress']);
                }
            }
        })();
    }

    private function activeSlugs(): array
    {
        $active = $this->app->db()->getOption('active_plugins', []);

        return is_array($active) ? array_values(array_filter($active, 'is_string')) : [];
    }

    private function assertValid(string $slug): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            throw new \InvalidArgumentException('Invalid plugin.');
        }

        if (!is_file($this->rootPath . '/kp-content/plugins/' . $slug . '/plugin.php')) {
            throw new \InvalidArgumentException('Plugin not found or missing plugin.php.');
        }
    }

    private function manifest(string $path): array
    {
        $file = $path . '/plugin.json';

        if (!is_file($file)) {
            return [];
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));
        $json = json_decode($contents, true);

        return is_array($json) ? $json : [];
    }

    private function slugFromTrace(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');

            if (preg_match('#/kp-content/plugins/([^/]+)/plugin\.php$#', $file, $match)) {
                return $match[1];
            }
        }

        return 'plugin';
    }

    private function slugFromFile(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        if (preg_match('#/kp-content/plugins/([^/]+)/#', $file, $match)) {
            return $match[1];
        }

        return $this->slugFromTrace();
    }

    private function runCallbacks(array $callbacks, string $slug): void
    {
        foreach ($callbacks as $callback) {
            $callback($slug);
        }
    }
}
