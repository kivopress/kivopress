<?php

declare(strict_types=1);

namespace KivopressSeo;

final class Plugin
{
    public function boot(): void
    {
        \plugin([
            'name' => 'Kivopress SEO',
            'description' => 'Search appearance controls, scoring, social meta tags, robots rules, sitemap images, and XML sitemaps.',
            'version' => '1.2.0',
            'author' => 'Kivopress',
        ]);

        \enqueue_admin_style('/kp-content/plugins/seo/admin.css');

        $settings = new Settings();
        $analyzer = new Analyzer();
        $frontend = new Frontend($settings);
        $sitemaps = new SitemapService($settings, $frontend);
        $metaBox = new MetaBox($settings, $analyzer, $frontend);
        $settingsPage = new Admin\SettingsPage($settings, $sitemaps);
        $sitemapPage = new Admin\SitemapPage($settings, $sitemaps, $settingsPage);

        $this->registerMenu();
        $this->registerFields();
        $this->watchSitemaps($sitemaps);

        \add_filter('content.field_input', [$metaBox, 'field']);
        \add_filter('content.meta_boxes', [$metaBox, 'decorate'], 20);
        \add_filter('content.publish_summary', [$analyzer, 'publishSummary'], 20);
        \add_filter('content.validate_payload', [$analyzer, 'validatePayload'], 20);
        \add_filter('admin.footer', fn (string $footer): string => $footer . $metaBox->script());
        \add_filter('theme.title', [$frontend, 'title']);
        \add_filter('theme.head', [$frontend, 'head']);

        \route('GET', '/admin/seo', fn () => $settingsPage->show());
        \route('POST', '/admin/seo', fn () => $settingsPage->save());
        \route('GET', '/admin/seo/sitemaps', fn () => $sitemapPage->show());
        \route('POST', '/admin/seo/generate', fn () => $sitemapPage->refresh());
        \route('GET', '/sitemap.xml', fn () => $sitemaps->index());
        \route('GET', '/sitemap_index.xml', fn () => $sitemaps->index());
        \route('GET', '/post-sitemap.xml', fn () => $sitemaps->type('post'));
        \route('GET', '/page-sitemap.xml', fn () => $sitemaps->type('page'));
        \route('GET', '/sitemap.xsl', fn () => $sitemaps->xsl());
        \route('GET', '/robots.txt', fn () => $sitemaps->robots());
    }

    private function registerMenu(): void
    {
        $item = \apply_filters('seo.admin_menu_item', [
            'href' => '/admin/seo',
            'label' => 'SEO',
            'icon' => 'search',
            'capability' => 'manage_settings',
            'position' => \apply_filters('seo.admin_menu_position', 82),
            'parent' => \apply_filters('seo.admin_menu_parent', ''),
        ]);

        \register_admin_menu($item);
    }

    private function registerFields(): void
    {
        foreach (['post', 'page'] as $type) {
            \register_fields($type, [
                'seo_title' => ['type' => 'text', 'label' => 'SEO Title', 'box' => 'SEO', 'box_priority' => 30],
                'seo_description' => ['type' => 'textarea', 'label' => 'Meta Description', 'box' => 'SEO', 'box_priority' => 30],
                'seo_canonical' => ['type' => 'text', 'label' => 'Canonical URL', 'box' => 'SEO', 'box_priority' => 30],
                'seo_sitemap_image' => ['type' => 'media', 'label' => 'Sitemap Image', 'box' => 'SEO', 'box_priority' => 30, 'description' => 'Optional image used in XML image sitemaps and social previews.'],
                'seo_noindex' => ['type' => 'boolean', 'label' => 'Hide from search engines', 'default' => false, 'box' => 'SEO', 'box_priority' => 30],
            ]);
        }
    }

    private function watchSitemaps(SitemapService $sitemaps): void
    {
        $hooks = \apply_filters('seo.sitemap_watch_hooks', [
            'content.saved',
            'content.deleted',
            'content.status_changed',
            'content.terms_saved',
            'taxonomy.term_created',
            'taxonomy.term_updated',
            'taxonomy.term_deleted',
            'settings.front_page_saved',
            'seo.settings.saved',
        ]);

        foreach (array_unique(array_filter((array) $hooks, 'is_string')) as $hook) {
            \add_action($hook, [$sitemaps, 'touch'], 10, PHP_INT_MAX);
        }
    }
}
