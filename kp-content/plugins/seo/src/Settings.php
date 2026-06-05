<?php

declare(strict_types=1);

namespace KivopressSeo;

final class Settings
{
    public const OPTION = 'kivopress_seo_settings';

    public function all(): array
    {
        $stored = \option(self::OPTION, []);

        return array_merge($this->defaults(), is_array($stored) ? $stored : []);
    }

    public function defaults(): array
    {
        return [
            'site_url' => '',
            'title_separator' => '-',
            'default_description' => '',
            'meta_robots' => true,
            'sitemap_enabled' => true,
            'sitemap_posts' => true,
            'sitemap_pages' => true,
            'sitemap_images' => true,
            'sitemap_robots' => true,
            'sitemap_limit' => 500,
        ];
    }

    public function save(array $input): array
    {
        $settings = [
            'site_url' => rtrim(trim((string) ($input['site_url'] ?? '')), '/'),
            'title_separator' => trim((string) ($input['title_separator'] ?? '-')) ?: '-',
            'default_description' => trim((string) ($input['default_description'] ?? '')),
            'meta_robots' => isset($input['meta_robots']),
            'sitemap_enabled' => isset($input['sitemap_enabled']),
            'sitemap_posts' => isset($input['sitemap_posts']),
            'sitemap_pages' => isset($input['sitemap_pages']),
            'sitemap_images' => isset($input['sitemap_images']),
            'sitemap_robots' => isset($input['sitemap_robots']),
            'sitemap_limit' => max(1, min(5000, (int) ($input['sitemap_limit'] ?? 500))),
        ];
        $errors = $this->validate($settings);

        if ($errors === []) {
            \do_action('seo.settings.saving', $settings, $input);
            \set_option(self::OPTION, $settings);
            \do_action('seo.settings.saved', $settings);
        }

        return $errors;
    }

    public function validate(array $settings): array
    {
        $errors = [];

        if ($settings['site_url'] !== '' && !filter_var($settings['site_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'SEO Site URL must be a valid absolute URL.';
        }

        if (mb_strlen((string) $settings['title_separator']) > 3) {
            $errors[] = 'Title separator must be 3 characters or fewer.';
        }

        if (mb_strlen((string) $settings['default_description']) > 320) {
            $errors[] = 'Default meta description must be 320 characters or fewer.';
        }

        return $errors;
    }

    public function baseUrl(): string
    {
        $configured = rtrim((string) ($this->all()['site_url'] ?? ''), '/');

        if ($configured !== '') {
            return $configured;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }
}
