<?php

declare(strict_types=1);

namespace KivopressSeo\Admin;

use Kivopress\Admin\AdminView;
use Kivopress\Response;
use KivopressSeo\Settings;
use KivopressSeo\SitemapService;

final class SitemapPage
{
    public function __construct(private Settings $settings, private SitemapService $sitemaps, private SettingsPage $settingsPage)
    {
    }

    public function show(): Response
    {
        if ($redirect = \app()->auth()->requireAdmin()) {
            return $redirect;
        }

        if (!\app()->auth()->can('manage_settings')) {
            return Response::html('Forbidden', 403);
        }

        $view = new AdminView(\app(), \app()->auth(), \app()->content());
        $base = $this->settings->baseUrl();
        $rows = '';

        foreach ($this->sitemaps->rows() as $row) {
            $rows .= '<tr><td><a class="kp-link-strong" href="' . \e($row['url']) . '" target="_blank" rel="noopener">' . \e($row['label']) . '</a></td><td>' . \e((string) $row['count']) . '</td><td>' . \e((string) $row['lastmod']) . '</td><td><span class="kp-pill ' . ($row['enabled'] ? 'kp-pill-live' : 'kp-pill-muted') . '">' . ($row['enabled'] ? 'Enabled' : 'Disabled') . '</span></td></tr>';
        }

        $touched = $this->sitemaps->touchedAt() ?: 'Waiting for content changes';
        $html = '<div class="kp-seo-dashboard">
            ' . $this->settingsPage->tabs('sitemaps') . '
            <section class="kp-seo-hero kp-seo-sitemap-hero"><div><p>XML Sitemaps</p><h2>Sitemap index</h2><span>The main sitemap links to each generated child sitemap.</span></div><form method="post" action="/admin/seo/generate">' . $view->csrfField() . '<button>' . $view->icon('refresh') . 'Refresh Sitemap</button></form></section>
            <section class="kp-seo-sitemap-index">
                <div class="kp-seo-index-card"><span>Main sitemap</span><a href="' . \e($base . '/sitemap_index.xml') . '" target="_blank" rel="noopener">' . \e($base . '/sitemap_index.xml') . '</a><p>This XML index contains links to every enabled child sitemap.</p></div>
                <div class="kp-seo-index-card"><span>Robots discovery</span><a href="' . \e($base . '/robots.txt') . '" target="_blank" rel="noopener">' . \e($base . '/robots.txt') . '</a><p>Search engines can discover the sitemap from robots.txt when enabled.</p></div>
                <div class="kp-seo-index-card"><span>Last sitemap update</span><strong>' . \e($touched) . '</strong><p>Updated automatically by content, taxonomy, and SEO settings hooks.</p></div>
            </section>
            <section class="kp-panel"><div class="kp-panel-head"><div><h2>Child Sitemaps</h2><p>Each row is linked from the main sitemap index.</p></div></div><div class="kp-table-wrap"><table><thead><tr><th>Sitemap</th><th>URLs</th><th>Last Modified</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4" class="kp-empty">No sitemap files are enabled.</td></tr>') . '</tbody></table></div></section>
        </div>';

        return $view->layout('SEO Sitemaps', $html);
    }

    public function refresh(): Response
    {
        if ($redirect = \app()->auth()->requireAdmin()) {
            return $redirect;
        }

        if (!\app()->auth()->can('manage_settings') || !\app()->auth()->validCsrf($_POST['_csrf'] ?? null)) {
            \app()->auth()->flash('error', 'Security check failed.');

            return Response::redirect('/admin/seo/sitemaps');
        }

        \set_option('kivopress_seo_sitemap_generated_at', gmdate('Y-m-d H:i:s'));
        $this->sitemaps->touch('manual_refresh');
        \app()->auth()->flash('notice', 'Sitemap refreshed. Kivopress serves sitemap XML dynamically.');

        return Response::redirect('/admin/seo/sitemaps');
    }
}
