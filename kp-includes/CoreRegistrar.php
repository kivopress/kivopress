<?php

declare(strict_types=1);

namespace Kivopress;

final class CoreRegistrar
{
    public function __construct(private App $app)
    {
    }

    public function registerMiddleware(): void
    {
        $this->app->middleware()->alias('api.rate_limit', function (Request $request, callable $next): mixed {
            if (!$request->isApi() || !$this->app->config('api.rate_limit.enabled', true)) {
                return $next($request);
            }

            $limit = max(1, (int) $this->app->config('api.rate_limit.max_attempts', 120));
            $window = max(60, (int) $this->app->config('api.rate_limit.window_seconds', 60));
            $token = $request->bearerToken();
            $key = 'api|' . $request->ip() . '|' . ($token ? hash('sha256', $token) : 'guest');
            $result = $this->app->rateLimiter()->attempt($key, $limit, $window);

            if (!$result['allowed']) {
                return Response::json(['error' => 'Too many API requests. Try again later.'], 429)
                    ->withHeader('Retry-After', (string) $result['retry_after'])
                    ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
                    ->withHeader('X-RateLimit-Remaining', '0');
            }

            $response = $next($request);

            return $response instanceof Response
                ? $response
                    ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
                    ->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
                : $response;
        });

        $this->app->middleware()->add('api.rate_limit');
        $this->app->hooks()->doAction('middleware.registered', $this->app->middleware());
    }

    public function registerMigrations(): void
    {
        $this->app->migrator()->register('2026_05_27_000001_api_token_lifecycle', function (Database $db): void {
            $string = $this->app->config('database.driver') === 'mysql' ? 'VARCHAR(191)' : 'TEXT';

            foreach (['expires_at', 'rotated_at'] as $column) {
                if (!$db->hasColumn('api_tokens', $column)) {
                    $db->addColumn('api_tokens', "{$column} {$string} NULL");
                }
            }
        }, 'Add API token expiration and rotation timestamps.');

        $this->app->migrator()->register('2026_06_05_000001_content_meta_multi_values', function (Database $db): void {
            $db->dropIndex('idx_content_meta_lookup', 'content_meta');
            $db->ensureIndex('idx_content_meta_lookup', 'content_meta', ['content_id', 'meta_key']);
        }, 'Allow multiple content meta rows per key.');

    }

    public function registerSchedules(): void
    {
        $this->app->scheduler()->schedule('kivopress.rate_limits.cleanup', 3600, fn (): null => $this->app->rateLimiter()->clearExpired());
        $this->app->scheduler()->schedule('kivopress.api_tokens.cleanup', 3600, fn (): int => $this->app->auth()->revokeExpiredApiTokens());
    }

    public function registerContentTypes(): void
    {
        $content = $this->app->content();

        $content->registerType('post', [
            'label' => 'Posts',
            'singular_label' => 'Post',
            'api_slug' => 'posts',
            'supports' => ['title', 'editor', 'excerpt', 'slug', 'status'],
        ]);

        $content->registerType('page', [
            'label' => 'Pages',
            'singular_label' => 'Page',
            'api_slug' => 'pages',
            'supports' => ['title', 'editor', 'slug', 'status'],
        ]);

        foreach (['post', 'page'] as $type) {
            $content->registerFields($type, [
                'featured_image' => [
                    'type' => 'media',
                    'label' => 'Featured Image',
                    'box' => 'Featured Image',
                    'box_context' => 'side',
                    'box_priority' => 30,
                ],
            ]);
        }

        $content->registerFields('page', [
            'page_template' => [
                'type' => 'select',
                'label' => 'Template',
                'box' => 'Page Attributes',
                'box_context' => 'side',
                'box_priority' => 25,
                'default' => '',
                'options_source' => 'theme.page_templates',
                'description' => 'Choose a validated template from the active theme.',
            ],
        ]);

        \add_filter('content.validate_payload', function (array $errors, array $schema, array $payload): array {
            if (($schema['name'] ?? '') !== 'page') {
                return $errors;
            }

            $template = (string) (($payload['fields'] ?? [])['page_template'] ?? '');

            if ($template !== '' && !$this->app->theme()->validPageTemplate($template)) {
                $errors[] = 'Choose a valid page template from the active theme.';
            }

            return $errors;
        }, 10, 3);

        $content->registerTaxonomy('category', [
            'label' => 'Categories',
            'singular_label' => 'Category',
            'content_types' => ['post'],
            'hierarchical' => true,
        ]);

        $content->registerTaxonomy('post_tag', [
            'label' => 'Tags',
            'singular_label' => 'Tag',
            'content_types' => ['post'],
            'hierarchical' => false,
        ]);

        if (!$content->term('category', 'uncategorized')) {
            $content->createTerm('category', ['name' => 'Uncategorized']);
        }
    }

    public function registerMenus(): void
    {
        $this->app->menus()->registerLocation('primary', 'Primary Menu', [
            'description' => 'Main site navigation.',
        ]);

        $this->app->menus()->registerLocation('footer', 'Footer Menu', [
            'description' => 'Footer navigation links.',
        ]);

        $this->app->hooks()->doAction('nav_menus_registered', $this->app->menus());
    }
}
