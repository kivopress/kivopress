<?php

declare(strict_types=1);

namespace KivopressSeo\Admin;

use Kivopress\Admin\AdminView;
use Kivopress\Response;
use KivopressSeo\Settings;
use KivopressSeo\SitemapService;

final class SettingsPage
{
    public function __construct(private Settings $settings, private SitemapService $sitemaps)
    {
    }

    public function show(): Response
    {
        if ($redirect = $this->guard()) {
            return $redirect;
        }

        $view = new AdminView(\app(), \app()->auth(), \app()->content());
        $settings = $this->settings->all();
        $links = '';

        foreach ($this->sitemaps->links() as $label => $url) {
            $links .= '<a class="kp-seo-link" href="' . \e($url) . '" target="_blank" rel="noopener">' . \e($label) . '</a>';
        }

        $html = '<div class="kp-seo-dashboard">
            ' . $this->tabs('settings') . '
            <section class="kp-seo-hero"><div><p>Kivopress SEO</p><h2>Search appearance and XML sitemaps</h2><span>Professional SEO controls without a heavy suite.</span></div><a class="kp-button" href="/admin/seo/sitemaps">' . $view->icon('sitemap') . 'Sitemap Console</a></section>
            <section class="kp-metric-grid">
                <div class="kp-metric-card"><span>Sitemaps</span><strong>' . ($settings['sitemap_enabled'] ? 'On' : 'Off') . '</strong></div>
                <div class="kp-metric-card"><span>Base URL</span><strong class="kp-seo-url-stat">' . \e(parse_url($this->settings->baseUrl(), PHP_URL_HOST) ?: $this->settings->baseUrl()) . '</strong></div>
                <div class="kp-metric-card"><span>Images</span><strong>' . ($settings['sitemap_images'] ? 'On' : 'Off') . '</strong></div>
            </section>
            <form method="post" action="/admin/seo" class="kp-form kp-seo-settings">' . $view->csrfField() . '
                <section class="kp-panel"><div class="kp-panel-head"><div><h2>Search Appearance</h2><p>Defaults used when content does not provide custom SEO fields.</p></div></div>
                    <div class="kp-seo-form-grid">
                        <label>Site URL<input type="url" name="site_url" value="' . \e((string) $settings['site_url']) . '" placeholder="' . \e($this->settings->baseUrl()) . '"></label>
                        <label>Title Separator<input name="title_separator" value="' . \e((string) $settings['title_separator']) . '" maxlength="3"></label>
                        <label class="kp-seo-wide">Default Meta Description<textarea name="default_description" rows="3" maxlength="320">' . \e((string) $settings['default_description']) . '</textarea></label>
                        <label class="kp-check"><input type="checkbox" name="meta_robots" value="1" ' . ($settings['meta_robots'] ? 'checked' : '') . '> Enable per-content robots controls</label>
                    </div>
                </section>
                <section class="kp-panel"><div class="kp-panel-head"><div><h2>XML Sitemaps</h2><p>Dynamic sitemap files are generated from published, indexable content.</p></div></div>
                    <div class="kp-seo-form-grid">
                        <label class="kp-check"><input type="checkbox" name="sitemap_enabled" value="1" ' . ($settings['sitemap_enabled'] ? 'checked' : '') . '> Enable XML sitemaps</label>
                        <label class="kp-check"><input type="checkbox" name="sitemap_posts" value="1" ' . ($settings['sitemap_posts'] ? 'checked' : '') . '> Include posts</label>
                        <label class="kp-check"><input type="checkbox" name="sitemap_pages" value="1" ' . ($settings['sitemap_pages'] ? 'checked' : '') . '> Include pages</label>
                        <label class="kp-check"><input type="checkbox" name="sitemap_images" value="1" ' . ($settings['sitemap_images'] ? 'checked' : '') . '> Include sitemap images</label>
                        <label class="kp-check"><input type="checkbox" name="sitemap_robots" value="1" ' . ($settings['sitemap_robots'] ? 'checked' : '') . '> Add sitemap to robots.txt</label>
                        <label>Maximum URLs per sitemap<input type="number" min="1" max="5000" name="sitemap_limit" value="' . \e((string) $settings['sitemap_limit']) . '"></label>
                    </div>
                    <div class="kp-seo-links">' . ($links ?: '<span class="kp-muted">Enable sitemaps to view generated files.</span>') . '</div>
                </section>
                <button>Save SEO Settings</button>
            </form>
        </div>';

        return $view->layout('SEO', $html);
    }

    public function save(): Response
    {
        if ($redirect = $this->guard(true)) {
            return $redirect;
        }

        $errors = $this->settings->save($_POST);

        if ($errors !== []) {
            \app()->auth()->flash('error', implode(' ', $errors));
        } else {
            \app()->auth()->flash('notice', 'SEO settings saved.');
        }

        return Response::redirect('/admin/seo');
    }

    public function tabs(string $active): string
    {
        $tabs = ['settings' => ['/admin/seo', 'Settings'], 'sitemaps' => ['/admin/seo/sitemaps', 'Sitemaps']];
        $html = '<nav class="kp-seo-tabs" aria-label="SEO sections">';

        foreach ($tabs as $key => [$href, $label]) {
            $html .= '<a href="' . \e($href) . '" class="' . ($active === $key ? 'is-active' : '') . '">' . \e($label) . '</a>';
        }

        return $html . '</nav>';
    }

    private function guard(bool $post = false): ?Response
    {
        if ($redirect = \app()->auth()->requireAdmin()) {
            return $redirect;
        }

        if (!\app()->auth()->can('manage_settings')) {
            return Response::html('Forbidden', 403);
        }

        if ($post && !\app()->auth()->validCsrf($_POST['_csrf'] ?? null)) {
            \app()->auth()->flash('error', 'Security check failed.');

            return Response::redirect('/admin/seo');
        }

        return null;
    }
}
