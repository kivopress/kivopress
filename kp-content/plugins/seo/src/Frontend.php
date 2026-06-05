<?php

declare(strict_types=1);

namespace KivopressSeo;

final class Frontend
{
    public function __construct(private Settings $settings)
    {
    }

    public function title(string $title, mixed $content): string
    {
        if (!is_array($content)) {
            return $title;
        }

        $seoTitle = trim((string) ($content['fields']['seo_title'] ?? ''));

        return $seoTitle !== '' ? $seoTitle : $title;
    }

    public function head(string $head, mixed $content, array $site): string
    {
        if (!is_array($content)) {
            return $head;
        }

        $settings = $this->settings->all();
        $fields = $content['fields'] ?? [];
        $title = trim((string) ($fields['seo_title'] ?? '')) ?: (string) ($content['title'] ?? $site['name']);
        $description = $this->description($content, $settings);
        $canonical = trim((string) ($fields['seo_canonical'] ?? '')) ?: $this->absoluteContentUrl($content);
        $image = $this->imageUrl($content);
        $tags = [];

        if ($description !== '') {
            $tags[] = '<meta name="description" content="' . \e($description) . '">';
            $tags[] = '<meta property="og:description" content="' . \e($description) . '">';
        }

        if ($title !== '') {
            $tags[] = '<meta property="og:title" content="' . \e($title) . '">';
            $tags[] = '<meta name="twitter:title" content="' . \e($title) . '">';
        }

        if ($canonical !== '' && filter_var($canonical, FILTER_VALIDATE_URL)) {
            $tags[] = '<link rel="canonical" href="' . \e($canonical) . '">';
            $tags[] = '<meta property="og:url" content="' . \e($canonical) . '">';
        }

        if ($image !== '') {
            $tags[] = '<meta property="og:image" content="' . \e($image) . '">';
        }

        if (($settings['meta_robots'] ?? true) && !empty($fields['seo_noindex'])) {
            $tags[] = '<meta name="robots" content="noindex,nofollow">';
        }

        return $head . "\n" . implode("\n", $tags) . "\n";
    }

    public function description(array $content, array $settings): string
    {
        $fields = $content['fields'] ?? [];
        $description = trim((string) ($fields['seo_description'] ?? ''));

        if ($description !== '') {
            return $description;
        }

        foreach (['excerpt', 'body'] as $key) {
            $value = trim(strip_tags((string) ($content[$key] ?? '')));

            if ($value !== '') {
                return mb_strimwidth($value, 0, 160, '');
            }
        }

        return trim((string) ($settings['default_description'] ?? ''));
    }

    public function absoluteContentUrl(array $content): string
    {
        return $this->settings->baseUrl() . \content_url($content);
    }

    public function imageUrl(array $content): string
    {
        $fields = $content['fields'] ?? [];
        $id = (int) ($fields['seo_sitemap_image'] ?? $fields['featured_image'] ?? 0);
        $item = $id > 0 ? \app()->media()->find($id) : null;

        if (!$item || empty($item['url'])) {
            return '';
        }

        return str_starts_with($item['url'], 'http') ? $item['url'] : $this->settings->baseUrl() . $item['url'];
    }
}
