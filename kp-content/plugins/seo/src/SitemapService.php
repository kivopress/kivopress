<?php

declare(strict_types=1);

namespace KivopressSeo;

use Kivopress\Response;

final class SitemapService
{
    public const UPDATED_OPTION = 'kivopress_seo_sitemap_updated_at';

    public function __construct(private Settings $settings, private Frontend $frontend)
    {
    }

    public function touch(mixed ...$context): void
    {
        \set_option(self::UPDATED_OPTION, gmdate('Y-m-d H:i:s'));
        \do_action('seo.sitemap.touched', $context);
    }

    public function index(): Response
    {
        $settings = $this->settings->all();

        if (!$settings['sitemap_enabled']) {
            return Response::html('Sitemap disabled.', 404);
        }

        $entries = [];

        foreach ($this->enabledTypes($settings) as $type => $file) {
            $lastmod = $this->latestLastmod($type) ?: gmdate('c');
            $entries[] = '<sitemap><loc>' . $this->xml($this->settings->baseUrl() . '/' . $file) . '</loc><lastmod>' . $this->xml($lastmod) . '</lastmod></sitemap>';
        }

        return $this->xmlResponse($this->xmlHeader() . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', $entries) . '</sitemapindex>');
    }

    public function type(string $type): Response
    {
        $settings = $this->settings->all();
        $key = 'sitemap_' . $type . 's';

        if (!$settings['sitemap_enabled'] || empty($settings[$key])) {
            return Response::html('Sitemap disabled.', 404);
        }

        $entries = [];

        foreach ($this->items($type, (int) $settings['sitemap_limit']) as $item) {
            if (!empty($item['fields']['seo_noindex'])) {
                continue;
            }

            $image = $settings['sitemap_images'] ? $this->imageXml($item) : '';
            $entries[] = '<url><loc>' . $this->xml($this->frontend->absoluteContentUrl($item)) . '</loc><lastmod>' . $this->xml($this->isoDate((string) $item['updated_at'])) . '</lastmod><changefreq>weekly</changefreq><priority>' . ($type === 'page' ? '0.8' : '0.7') . '</priority>' . $image . '</url>';
        }

        return $this->xmlResponse($this->xmlHeader() . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . implode('', $entries) . '</urlset>');
    }

    public function rows(): array
    {
        $settings = $this->settings->all();
        $rows = [];

        foreach (['post' => ['label' => 'Posts Sitemap', 'file' => 'post-sitemap.xml'], 'page' => ['label' => 'Pages Sitemap', 'file' => 'page-sitemap.xml']] as $type => $row) {
            $enabled = (bool) $settings['sitemap_enabled'] && (bool) $settings['sitemap_' . $type . 's'];
            $rows[] = [
                'label' => $row['label'],
                'url' => $this->settings->baseUrl() . '/' . $row['file'],
                'count' => $enabled ? $this->countIndexable($type, (int) $settings['sitemap_limit']) : 0,
                'lastmod' => $enabled ? ($this->latestLastmod($type) ?: 'No published content') : 'Disabled',
                'enabled' => $enabled,
            ];
        }

        return $rows;
    }

    public function links(): array
    {
        $settings = $this->settings->all();

        if (!$settings['sitemap_enabled']) {
            return [];
        }

        $links = ['Sitemap Index' => $this->settings->baseUrl() . '/sitemap_index.xml'];

        foreach ($this->enabledTypes($settings) as $type => $file) {
            $links[ucfirst($type) . 's Sitemap'] = $this->settings->baseUrl() . '/' . $file;
        }

        return $links;
    }

    public function touchedAt(): ?string
    {
        $value = \option(self::UPDATED_OPTION, null);

        return is_string($value) ? $value : null;
    }

    public function robots(): Response
    {
        $settings = $this->settings->all();
        $lines = ['User-agent: *', 'Allow: /'];

        if ($settings['sitemap_enabled'] && $settings['sitemap_robots']) {
            $lines[] = 'Sitemap: ' . $this->settings->baseUrl() . '/sitemap_index.xml';
        }

        return new Response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function xsl(): Response
    {
        $xsl = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html><head><title>Kivopress XML Sitemap</title><style>
body{background:#f5f7f4;color:#17211e;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0}
main{margin:0 auto;max-width:1040px;padding:34px 22px}.hero{background:linear-gradient(135deg,#f7fbf8,#eff2ff);border:1px solid #d9e1dc;border-radius:6px;margin-bottom:16px;padding:18px}.eyebrow{color:#61716b;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
h1{font-size:28px;line-height:1.15;margin:4px 0}p{color:#61716b;margin:0}table{background:#fff;border:1px solid #d9e1dc;border-collapse:separate;border-radius:6px;border-spacing:0;box-shadow:0 8px 24px rgba(23,33,30,.06);overflow:hidden;width:100%}
th,td{border-bottom:1px solid #d9e1dc;padding:11px 13px;text-align:left;vertical-align:top}tr:last-child td{border-bottom:0}th{background:#fbfcfb;color:#61716b;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}a{color:#176955;font-weight:700;text-decoration:none;overflow-wrap:anywhere}.muted{color:#61716b}
@media(max-width:640px){main{padding:22px}.hero{padding:16px}h1{font-size:26px}table,thead,tbody,tr,th,td{display:block}thead{display:none}tr{border-bottom:1px solid #d9e1dc}tr:last-child{border-bottom:0}td{border-bottom:0;padding:9px 12px}td:before{color:#61716b;display:block;font-size:10px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.sitemap-index td:first-child:before{content:"Sitemap"}.sitemap-index td:last-child:before{content:"Last Modified"}.urlset td:nth-child(1):before{content:"URL"}.urlset td:nth-child(2):before{content:"Last Modified"}.urlset td:nth-child(3):before{content:"Images"}}</style></head>
<body><main><section class="hero"><div class="eyebrow">Kivopress SEO</div><h1>XML Sitemap</h1><p>This sitemap is generated dynamically and kept intentionally lightweight.</p></section>
<xsl:choose><xsl:when test="sitemap:sitemapindex"><table class="sitemap-index"><thead><tr><th>Sitemap</th><th>Last Modified</th></tr></thead><tbody><xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap"><tr><td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td><td class="muted"><xsl:value-of select="sitemap:lastmod"/></td></tr></xsl:for-each></tbody></table></xsl:when>
<xsl:otherwise><table class="urlset"><thead><tr><th>URL</th><th>Last Modified</th><th>Images</th></tr></thead><tbody><xsl:for-each select="sitemap:urlset/sitemap:url"><tr><td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td><td class="muted"><xsl:value-of select="sitemap:lastmod"/></td><td class="muted"><xsl:value-of select="count(image:image)"/></td></tr></xsl:for-each></tbody></table></xsl:otherwise></xsl:choose>
</main></body></html></xsl:template></xsl:stylesheet>
XML;

        return new Response($xsl, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function enabledTypes(array $settings): array
    {
        $types = [];

        foreach (['post' => 'post-sitemap.xml', 'page' => 'page-sitemap.xml'] as $type => $file) {
            if (!empty($settings['sitemap_' . $type . 's'])) {
                $types[$type] = $file;
            }
        }

        return $types;
    }

    private function items(string $type, int $limit): array
    {
        $items = [];
        $offset = 0;

        do {
            $batch = \app()->content()->all($type, ['limit' => 100, 'offset' => $offset]);
            $items = array_merge($items, $batch);
            $offset += 100;
        } while (count($batch) === 100 && count($items) < $limit);

        return array_slice($items, 0, $limit);
    }

    private function latestLastmod(string $type): ?string
    {
        $latest = null;

        foreach ($this->items($type, 500) as $item) {
            if (!empty($item['fields']['seo_noindex'])) {
                continue;
            }

            $date = $this->isoDate((string) $item['updated_at']);
            $latest = $latest === null || $date > $latest ? $date : $latest;
        }

        return $latest;
    }

    private function countIndexable(string $type, int $limit): int
    {
        return count(array_filter($this->items($type, $limit), fn (array $item): bool => empty($item['fields']['seo_noindex'])));
    }

    private function imageXml(array $item): string
    {
        $url = $this->frontend->imageUrl($item);

        return $url === '' ? '' : '<image:image><image:loc>' . $this->xml($url) . '</image:loc></image:image>';
    }

    private function isoDate(string $date): string
    {
        return gmdate('c', strtotime($date) ?: time());
    }

    private function xmlResponse(string $xml): Response
    {
        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function xmlHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>' . "\n";
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
