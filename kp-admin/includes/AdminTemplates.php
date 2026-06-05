<?php

declare(strict_types=1);

namespace Kivopress\Admin;

use Kivopress\App;

final class AdminTemplates
{
    public function __construct(private App $app)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $template = $this->cleanTemplate($template);
        $data = \apply_filters('admin.template_data', $data, $template);
        $file = $this->locate($template, $data);
        $file = \apply_filters('admin.template_file', $file, $template, $data);

        if (!is_string($file) || !is_file($file)) {
            throw new \RuntimeException('Admin template not found: ' . $template);
        }

        \do_action('admin.template_before', $template, $data, $file);

        $html = $this->include($file, $data + [
            'app' => $this->app,
            'template' => $template,
        ]);

        $html = (string) \apply_filters('admin.template_html', $html, $template, $data, $file);
        \do_action('admin.template_after', $template, $html, $data, $file);

        return $html;
    }

    public function locate(string $template, array $data = []): string
    {
        $template = $this->cleanTemplate($template);
        $paths = [
            $this->app->path('kp-content/themes/' . $this->app->theme()->active() . '/admin'),
            $this->app->path('kp-admin/templates'),
        ];

        $paths = \apply_filters('admin.template_paths', $paths, $template, $data);

        foreach ((array) $paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }

            $base = realpath($path);

            if ($base === false || !is_dir($base)) {
                continue;
            }

            $file = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template) . '.php';
            $real = realpath($file);

            if ($real !== false && is_file($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return '';
    }

    private function include(string $file, array $data): string
    {
        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            require $file;

            return (string) ob_get_clean();
        })();
    }

    private function cleanTemplate(string $template): string
    {
        $template = trim(str_replace('\\', '/', $template), '/');

        if ($template === '' || str_contains($template, '..') || !preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$#', $template)) {
            throw new \InvalidArgumentException('Invalid admin template reference.');
        }

        return $template;
    }
}
