<?php

declare(strict_types=1);

namespace Kivopress;

final class Theme
{
    private ?string $activeCache = null;
    private array $manifestCache = [];
    private array $pageTemplateCache = [];
    private array $validationCache = [];
    private array $validationInProgress = [];

    public function __construct(private App $app, private string $rootPath, private string $activeTheme)
    {
    }

    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->renderCandidates([$template, 'index'], $data, $status);
    }

    public function renderCandidates(array $templates, array $data = [], int $status = 200): Response
    {
        $file = $this->resolveTemplate($templates);

        if (!is_file($file)) {
            return Response::html('Theme template not found.', 500);
        }

        $template = $this->templateName($file, $this->themePath());
        $data = apply_filters('theme.template_data', $data, $template, $status);
        do_action('theme.rendering', $template, $data, $status);
        do_action('theme.before_render', $template, $data, $status);
        $html = $this->capture($file, $data);
        $html = apply_filters('theme.rendered', $html, $template, $data, $status);
        do_action('theme.rendered', $html, $template, $data, $status);
        do_action('theme.after_render', $html, $template, $data, $status);
        $html = (new AdminBar($this->app))->inject($html, $data);

        return Response::html($html, $status);
    }

    public function renderHome(): Response
    {
        $page = $this->frontPage();

        if ($page) {
            return $this->renderCandidates($this->pageTemplateCandidates($page), [
                'content' => $page,
                'page' => $page,
                'is_front_page' => true,
                'is_static_front_page' => true,
            ]);
        }

        $perPage = max(1, min(24, (int) apply_filters('theme.posts_per_page', 9, 'home')));
        $pageNumber = max(1, (int) $this->app->request()->query('paged', 1));
        $query = apply_filters('theme.home_query', [
            'limit' => $perPage,
            'offset' => ($pageNumber - 1) * $perPage,
        ]);
        $posts = $this->app->content()->all('post', $query);
        $total = $this->app->content()->countAll('post', $query);

        return $this->renderCandidates(['front-page', 'home', 'index'], [
            'content' => null,
            'page' => null,
            'posts' => $posts,
            'pagination' => $this->pagination($pageNumber, $perPage, $total),
            'is_front_page' => true,
            'is_static_front_page' => false,
        ]);
    }

    public function renderSlug(string $slug): Response
    {
        return $this->renderPath($slug);
    }

    public function renderPath(string $path): Response
    {
        if ($archive = $this->archiveForPath($path)) {
            return $this->renderCandidates($archive['templates'], $archive['data']);
        }

        $slug = $this->slugFromPath($path);
        $page = $this->app->content()->find('page', $slug);

        if ($page) {
            if ($this->isFrontPage($page)) {
                $status = (int) apply_filters('theme.front_page_redirect_status', 301, $page, $path);

                return Response::redirect('/', $status);
            }

            return $this->renderCandidates($this->pageTemplateCandidates($page), ['content' => $page, 'page' => $page]);
        }

        if ($staticPage = $this->staticThemePage($slug)) {
            return $this->renderCandidates($this->pageTemplateCandidates($staticPage), [
                'content' => $staticPage,
                'page' => $staticPage,
                'is_static_theme_page' => true,
            ]);
        }

        $post = $this->app->content()->find('post', $slug);

        if ($post) {
            return $this->renderCandidates($this->singleTemplateCandidates($post), ['content' => $post, 'post' => $post]);
        }

        return $this->render('404', ['slug' => $slug], 404);
    }

    public function contentUrl(array $content): string
    {
        if ($this->isFrontPage($content)) {
            return '/';
        }

        $slug = trim((string) ($content['slug'] ?? ''), '/');

        if ($slug === '') {
            return '#';
        }

        $type = (string) ($content['type'] ?? 'post');
        $structure = $type === 'post'
            ? (string) $this->app->db()->getOption('permalink_structure', '/%postname%/')
            : '/%postname%/';
        $path = $this->applyPermalinkStructure($structure, $content);

        return '/' . trim($path, '/') . '/';
    }

    public function active(): string
    {
        if ($this->activeCache !== null) {
            return $this->activeCache;
        }

        $active = (string) $this->app->db()->getOption('active_theme', $this->activeTheme);

        if ($this->hasFallbackTemplate($active)) {
            return $this->activeCache = $active;
        }

        return $this->activeCache = $this->hasFallbackTemplate($this->activeTheme) ? $this->activeTheme : 'default';
    }

    public function all(): array
    {
        $themesPath = $this->rootPath . '/kp-content/themes';
        $themes = [];

        foreach (glob($themesPath . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            $manifest = $this->manifest($path);
            $validation = $this->validate($slug);

            $themes[] = [
                'slug' => $slug,
                'name' => $manifest['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug)),
                'description' => $manifest['description'] ?? 'A Kivopress theme.',
                'version' => $manifest['version'] ?? '1.0.0',
                'author' => $manifest['author'] ?? 'Unknown',
                'active' => $slug === $this->active(),
                'valid' => $validation['valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'page_templates' => $validation['page_templates'],
            ];
        }

        usort($themes, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $themes;
    }

    public function activate(string $slug): void
    {
        $validation = $this->validate($slug);

        if (!$validation['valid']) {
            throw new \InvalidArgumentException('Theme validation failed: ' . implode(' ', $validation['errors']));
        }

        $this->app->db()->setOption('active_theme', $slug);
        $this->activeCache = $slug;
    }

    public function pageTemplates(): array
    {
        return $this->pageTemplatesFor($this->active());
    }

    public function validPageTemplate(string $template): bool
    {
        $template = $this->cleanTemplate($template);

        return $template === '' || array_key_exists($template, $this->pageTemplates());
    }

    public function validate(string $slug): array
    {
        if (isset($this->validationCache[$slug])) {
            return $this->validationCache[$slug];
        }

        if (isset($this->validationInProgress[$slug])) {
            return $this->validationResult(true, [], [], $this->pageTemplateCache[$slug] ?? ['' => 'Default Template']);
        }

        $this->validationInProgress[$slug] = true;
        $errors = [];
        $warnings = [];

        if (!$this->validSlug($slug)) {
            $errors[] = 'Theme folder names may only contain letters, numbers, dashes, and underscores.';
            unset($this->validationInProgress[$slug]);

            return $this->validationCache[$slug] = $this->validationResult(false, $errors, $warnings, []);
        }

        $path = $this->rootPath . '/kp-content/themes/' . $slug;
        $manifestFile = $path . '/theme.json';
        $manifest = $this->manifest($path);

        if (!is_dir($path)) {
            $errors[] = 'Theme folder does not exist.';
        }

        if (!is_file($path . '/index.php')) {
            $errors[] = 'Missing required index.php fallback template.';
        }

        if (!is_file($manifestFile)) {
            $warnings[] = 'Missing theme.json manifest.';
        } elseif ($manifest === []) {
            $errors[] = 'theme.json is not valid JSON.';
        }

        foreach (['name', 'version'] as $field) {
            if (is_file($manifestFile) && trim((string) ($manifest[$field] ?? '')) === '') {
                $warnings[] = 'theme.json should include "' . $field . '".';
            }
        }

        $recommendedTemplates = ['single.php', '404.php'];

        if (($manifest['static_page_templates'] ?? false) !== true) {
            $recommendedTemplates[] = 'page.php';
        }

        foreach ($recommendedTemplates as $recommended) {
            if (!is_file($path . '/' . $recommended)) {
                $warnings[] = 'Recommended template missing: ' . $recommended . '.';
            }
        }

        $pageTemplates = $this->pageTemplatesFor($slug);

        foreach (array_keys($pageTemplates) as $template) {
            if ($template !== '' && !$this->validTemplateReference($template)) {
                $errors[] = 'Invalid page template reference: ' . $template . '.';
            }
        }

        unset($this->validationInProgress[$slug]);

        return $this->validationCache[$slug] = $this->validationResult($errors === [], $errors, $warnings, $pageTemplates);
    }

    private function capture(string $file, array $data): string
    {
        $site = [
            'name' => $this->app->db()->getOption('site_name', $this->app->config('name', 'Kivopress')),
            'url' => '/',
        ];
        $contentRepository = $this->app->content();
        $app = $this->app;

        extract($data, EXTR_SKIP);

        ob_start();
        $previous = $GLOBALS['kivopress'] ?? null;
        $previousThemeData = $GLOBALS['kivopress_theme_data'] ?? null;
        $GLOBALS['kivopress'] = $this->app;
        $GLOBALS['kivopress_theme_data'] = $data;

        try {
            $functions = $this->themePath() . '/functions.php';

            if (is_file($functions)) {
                require_once $functions;
            }

            require $file;

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        } finally {
            if ($previous) {
                $GLOBALS['kivopress'] = $previous;
            } else {
                unset($GLOBALS['kivopress']);
            }

            if ($previousThemeData !== null) {
                $GLOBALS['kivopress_theme_data'] = $previousThemeData;
            } else {
                unset($GLOBALS['kivopress_theme_data']);
            }
        }
    }

    private function themePath(): string
    {
        return $this->rootPath . '/kp-content/themes/' . $this->active();
    }

    private function slugFromPath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));

        return (string) end($segments);
    }

    private function archiveForPath(string $path): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn (string $segment): bool => $segment !== ''));
        $first = $segments[0] ?? '';

        if ($first === 'search') {
            $query = trim((string) ($this->app->request()->query('s', '') ?: $this->app->request()->query('q', '')));

            return $this->archiveData('search', [
                'title' => $query !== '' ? 'Search results for "' . $query . '"' : 'Search',
                'description' => 'Search published content.',
                'search' => $query,
            ], ['search', 'archive', 'index']);
        }

        $taxonomy = match ($first) {
            'category' => 'category',
            'tag' => 'post_tag',
            default => '',
        };

        if ($taxonomy === '' || empty($segments[1])) {
            return null;
        }

        $term = $this->app->content()->term($taxonomy, $segments[1]);

        if (!$term) {
            return null;
        }

        return $this->archiveData($taxonomy, [
            'title' => $term['name'],
            'description' => $term['description'] ?? '',
            'term' => $term,
            'taxonomy' => $taxonomy,
            'terms' => [$taxonomy => $term['id']],
        ], ['taxonomy-' . $taxonomy, 'taxonomy', 'archive', 'index']);
    }

    private function archiveData(string $context, array $archive, array $templates): array
    {
        $perPage = max(1, min(24, (int) apply_filters('theme.posts_per_page', 9, $context)));
        $pageNumber = max(1, (int) $this->app->request()->query('paged', 1));
        $query = [
            'limit' => $perPage,
            'offset' => ($pageNumber - 1) * $perPage,
        ];

        if (!empty($archive['search'])) {
            $query['search'] = $archive['search'];
        }

        if (!empty($archive['terms']) && is_array($archive['terms'])) {
            $query['terms'] = $archive['terms'];
        }

        $query = apply_filters('theme.archive_query', $query, $archive, $context);
        $posts = $this->app->content()->all('post', $query);
        $total = $this->app->content()->countAll('post', $query);

        return [
            'templates' => $templates,
            'data' => [
                'archive' => $archive,
                'posts' => $posts,
                'pagination' => $this->pagination($pageNumber, $perPage, $total),
            ],
        ];
    }

    private function frontPage(): ?array
    {
        $frontPageId = $this->app->db()->getOption('front_page_id', null);

        if ($frontPageId !== null) {
            $id = (int) $frontPageId;

            return $id > 0 ? $this->app->content()->find('page', $id) : null;
        }

        return $this->app->content()->find('page', 'home');
    }

    private function isFrontPage(array $content): bool
    {
        if (($content['type'] ?? '') !== 'page') {
            return false;
        }

        $frontPageId = $this->app->db()->getOption('front_page_id', null);

        if ($frontPageId !== null) {
            return (int) $frontPageId > 0 && (int) ($content['id'] ?? 0) === (int) $frontPageId;
        }

        return (string) ($content['slug'] ?? '') === 'home';
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private function applyPermalinkStructure(string $structure, array $content): string
    {
        $slug = trim((string) ($content['slug'] ?? ''), '/');
        $date = strtotime((string) ($content['published_at'] ?? $content['created_at'] ?? 'now')) ?: time();
        $replacements = [
            '%year%' => date('Y', $date),
            '%monthnum%' => date('m', $date),
            '%day%' => date('d', $date),
            '%postname%' => $slug,
        ];

        $path = strtr($structure ?: '/%postname%/', $replacements);

        if (!str_contains($path, $slug)) {
            $path = rtrim($path, '/') . '/' . $slug;
        }

        return $path;
    }

    private function staticThemePage(string $slug): ?array
    {
        $manifest = $this->manifest($this->themePath());

        if (($manifest['static_page_templates'] ?? false) !== true) {
            return null;
        }

        $slug = $this->cleanTemplate($slug);

        if ($slug === '' || !$this->validTemplateReference('page-' . $slug)) {
            return null;
        }

        $template = 'page-' . $slug;
        $file = $this->themePath() . '/' . $template . '.php';

        if (!is_file($file)) {
            return null;
        }

        $title = $this->templateHeader($file) ?: ucwords(str_replace('-', ' ', $slug));

        return [
            'id' => 0,
            'type' => 'page',
            'status' => 'published',
            'slug' => $slug,
            'title' => $title,
            'body' => '',
            'excerpt' => '',
            'author_id' => null,
            'published_at' => null,
            'created_at' => null,
            'updated_at' => null,
            'fields' => ['page_template' => $template],
            'terms' => [],
        ];
    }

    private function manifest(string $path): array
    {
        if (array_key_exists($path, $this->manifestCache)) {
            return $this->manifestCache[$path];
        }

        $file = $path . '/theme.json';

        if (!is_file($file)) {
            return $this->manifestCache[$path] = [];
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file));
        $json = json_decode($contents, true);

        return $this->manifestCache[$path] = is_array($json) ? $json : [];
    }

    private function resolveTemplate(array $templates): string
    {
        $themePath = $this->themePath();

        foreach (array_filter(array_map(fn (mixed $template): string => $this->cleanTemplate((string) $template), $templates)) as $template) {
            if (!$this->validTemplateReference($template)) {
                continue;
            }

            $file = $themePath . '/' . $template . '.php';

            if (is_file($file)) {
                return $file;
            }
        }

        return $themePath . '/index.php';
    }

    private function pageTemplateCandidates(array $page, bool $includeDefault = true): array
    {
        $templates = [];
        $selected = $this->cleanTemplate((string) ($page['fields']['page_template'] ?? ''));

        if ($selected !== '' && $this->validPageTemplate($selected)) {
            $templates[] = $selected;
        }

        $templates[] = 'page-' . $this->cleanTemplate((string) ($page['slug'] ?? ''));
        $templates[] = 'page-' . (int) ($page['id'] ?? 0);

        if ($includeDefault) {
            $templates[] = 'page';
            $templates[] = 'index';
        }

        return $templates;
    }

    private function singleTemplateCandidates(array $content): array
    {
        $type = $this->cleanTemplate((string) ($content['type'] ?? 'post'));

        return ['single-' . $type, 'single', 'index'];
    }

    private function pageTemplatesFor(string $slug): array
    {
        if (isset($this->pageTemplateCache[$slug])) {
            return $this->pageTemplateCache[$slug];
        }

        if (!$this->validSlug($slug)) {
            return ['' => 'Default Template'];
        }

        $path = $this->rootPath . '/kp-content/themes/' . $slug;
        $manifest = $this->manifest($path);
        $templates = ['' => 'Default Template'];

        foreach ((array) ($manifest['page_templates'] ?? $manifest['templates'] ?? []) as $key => $label) {
            if (is_array($label)) {
                $key = (string) ($label['file'] ?? $label['template'] ?? $key);
                $label = (string) ($label['name'] ?? $label['label'] ?? $key);
            }

            $key = $this->cleanTemplate((string) $key);

            if ($key !== '' && is_file($path . '/' . $key . '.php')) {
                $templates[$key] = (string) $label;
            }
        }

        foreach ($this->templateFiles($path) as $file) {
            $header = $this->templateHeader($file);

            if ($header !== '') {
                $templates[$this->templateName($file, $path)] = $header;
            }
        }

        $this->pageTemplateCache[$slug] = $templates;

        return $this->pageTemplateCache[$slug] = apply_filters('theme.page_templates', $templates, $slug);
    }

    private function templateFiles(string $path): array
    {
        $files = glob($path . '/*.php') ?: [];
        $files = array_merge($files, glob($path . '/templates/*.php') ?: []);

        return array_values(array_filter($files, fn (string $file): bool => is_file($file)));
    }

    private function templateHeader(string $file): string
    {
        $head = (string) file_get_contents($file, false, null, 0, 2048);

        return preg_match('/Template Name:\s*(.+)$/mi', $head, $match) ? trim($match[1]) : '';
    }

    private function templateName(string $file, string $basePath): string
    {
        $themePath = str_replace('\\', '/', rtrim($basePath, '/\\')) . '/';
        $file = str_replace('\\', '/', $file);
        $relative = str_starts_with($file, $themePath) ? substr($file, strlen($themePath)) : basename($file);

        return $this->cleanTemplate(preg_replace('/\.php$/', '', $relative) ?: 'index');
    }

    private function cleanTemplate(string $template): string
    {
        $template = trim(str_replace('\\', '/', $template), '/');
        $template = preg_replace('/\.php$/i', '', $template) ?: '';

        return trim($template, '/');
    }

    private function validTemplateReference(string $template): bool
    {
        return $template !== ''
            && !str_contains($template, '..')
            && preg_match('#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?$#', $template);
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $slug) === 1;
    }

    private function hasFallbackTemplate(string $slug): bool
    {
        return $this->validSlug($slug) && is_file($this->rootPath . '/kp-content/themes/' . $slug . '/index.php');
    }

    private function validationResult(bool $valid, array $errors, array $warnings, array $pageTemplates): array
    {
        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'page_templates' => $pageTemplates,
        ];
    }
}
