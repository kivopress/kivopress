<?php

declare(strict_types=1);

if (!function_exists('kp_default_asset')) {
    function kp_default_asset(string $path): string
    {
        return '/kp-content/themes/' . rawurlencode(app()->theme()->active()) . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('kp_default_body_class')) {
    function kp_default_body_class(string $context = ''): string
    {
        $classes = array_filter(['kp-site', $context]);

        return implode(' ', apply_filters('kivopress_default_body_classes', $classes, $context));
    }
}

if (!function_exists('kp_default_excerpt')) {
    function kp_default_excerpt(array $content, int $length = 170): string
    {
        $text = trim((string) ($content['excerpt'] ?? ''));

        if ($text === '') {
            $text = trim(strip_tags((string) ($content['body'] ?? '')));
        }

        $text = preg_replace('/\s+/', ' ', $text) ?: '';

        return apply_filters('kivopress_default_excerpt', mb_strimwidth($text, 0, $length, '...'), $content, $length);
    }
}

if (!function_exists('kp_default_read_time')) {
    function kp_default_read_time(array $content): string
    {
        $words = str_word_count(strip_tags((string) ($content['body'] ?? '')));
        $minutes = max(1, (int) ceil($words / 220));

        return $minutes . ' min read';
    }
}

if (!function_exists('kp_default_date')) {
    function kp_default_date(array $content): string
    {
        $raw = (string) ($content['published_at'] ?? $content['created_at'] ?? '');
        $timestamp = strtotime($raw) ?: time();

        return date('M j, Y', $timestamp);
    }
}

if (!function_exists('kp_default_featured_image')) {
    function kp_default_featured_image(array $content): ?array
    {
        $id = (int) ($content['fields']['featured_image'] ?? 0);

        return $id > 0 ? media($id) : null;
    }
}

if (!function_exists('kp_default_featured_image_html')) {
    function kp_default_featured_image_html(array $content, string $class = 'kp-featured-image'): string
    {
        $image = kp_default_featured_image($content);

        if (!$image || empty($image['url'])) {
            return '';
        }

        $alt = $image['alt'] ?: ($image['title'] ?: $content['title']);

        return '<figure class="' . e($class) . '"><img src="' . e($image['url']) . '" alt="' . e($alt) . '"></figure>';
    }
}

if (!function_exists('kp_default_terms')) {
    function kp_default_terms(array $content, string $taxonomy): array
    {
        return (array) ($content['terms'][$taxonomy] ?? []);
    }
}

if (!function_exists('kp_default_term_url')) {
    function kp_default_term_url(array $term): string
    {
        $base = ($term['taxonomy'] ?? '') === 'post_tag' ? 'tag' : 'category';

        return '/' . $base . '/' . trim((string) ($term['slug'] ?? ''), '/') . '/';
    }
}

if (!function_exists('kp_default_nav_items')) {
    function kp_default_nav_items(array $site, mixed $contentRepository): array
    {
        $items = [['label' => 'Home', 'href' => '/']];

        foreach ($contentRepository->all('page', ['limit' => 8, 'orderby' => 'title', 'order' => 'asc']) as $page) {
            if (($page['slug'] ?? '') === 'home' || content_url($page) === '/') {
                continue;
            }

            $items[] = ['label' => $page['title'], 'href' => content_url($page)];
        }

        return apply_filters('kivopress_default_nav_items', $items, $site);
    }
}

if (!function_exists('kp_default_primary_navigation')) {
    function kp_default_primary_navigation(array $site, mixed $contentRepository): string
    {
        $menu = kp_nav_menu([
            'theme_location' => 'primary',
            'class' => 'kp-nav',
            'menu_class' => 'kp-nav-list',
            'aria_label' => 'Primary navigation',
        ]);

        if ($menu !== '') {
            return $menu;
        }

        $links = '';

        foreach (kp_default_nav_items($site, $contentRepository) as $item) {
            $links .= '<a href="' . e((string) ($item['href'] ?? '#')) . '">' . e((string) ($item['label'] ?? '')) . '</a>';
        }

        return '<nav class="kp-nav" aria-label="Primary navigation">' . $links . '</nav>';
    }
}

if (!function_exists('kp_default_footer_columns')) {
    function kp_default_footer_columns(array $site): array
    {
        $footerMenu = app()->menus()->items('footer');

        return apply_filters('kivopress_default_footer_columns', [
            'Explore' => $footerMenu !== [] ? array_map(static fn (array $item): array => [
                'label' => (string) ($item['label'] ?? ''),
                'href' => (string) ($item['url'] ?? '#'),
            ], $footerMenu) : [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Search', 'href' => '/search/'],
                ['label' => 'REST API', 'href' => '/api'],
            ],
            'Developers' => [
                ['label' => 'Posts JSON', 'href' => '/api/posts'],
                ['label' => 'Pages JSON', 'href' => '/api/pages'],
            ],
        ], $site);
    }
}

if (!function_exists('kp_default_pagination')) {
    function kp_default_pagination(array $pagination): string
    {
        $page = (int) ($pagination['page'] ?? 1);
        $total = (int) ($pagination['total_pages'] ?? 1);

        if ($total <= 1) {
            return '';
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = $_GET;
        $items = '';

        for ($i = 1; $i <= $total; $i++) {
            $query['paged'] = $i;
            $href = $path . '?' . http_build_query($query);
            $items .= '<a class="' . ($i === $page ? 'is-active' : '') . '" href="' . e($href) . '">' . $i . '</a>';
        }

        return '<nav class="kp-pagination" aria-label="Pagination">' . $items . '</nav>';
    }
}

if (!function_exists('kp_default_search_form')) {
    function kp_default_search_form(string $value = ''): string
    {
        return '<form class="kp-search" method="get" action="/search/">
            <label><span>Search</span><input name="s" value="' . e($value) . '" placeholder="Search articles"></label>
            <button>Search</button>
        </form>';
    }
}
