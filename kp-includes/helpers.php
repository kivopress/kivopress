<?php

declare(strict_types=1);

use Kivopress\App;

function app(): App
{
    return $GLOBALS['kivopress'];
}

function config(string $key = null, mixed $default = null): mixed
{
    if ($key === null) {
        return app()->config();
    }

    return app()->config($key, $default);
}

function db(): Kivopress\Database
{
    return app()->db();
}

function db_table(string $name): string
{
    return app()->tables()->name($name);
}

function db_delta(string $name, array $schema): string
{
    return app()->tables()->create($name, $schema);
}

function media(int $id): ?array
{
    return app()->media()->find($id);
}

function media_url(int $id): ?string
{
    $item = media($id);

    return $item['url'] ?? null;
}

function route(string $method, string $path, callable $handler, array $middleware = []): void
{
    app()->router()->add($method, $path, $handler, $middleware);
}

function request(): Kivopress\Request
{
    return app()->request();
}

function add_middleware(callable|string $middleware): void
{
    app()->middleware()->add($middleware);
}

function middleware_alias(string $name, callable|string $middleware): void
{
    app()->middleware()->alias($name, $middleware);
}

function validate(array $data, array $rules): array
{
    return (new Kivopress\Validator())->validate($data, $rules);
}

function register_rest_route(string $namespace, string $route, array $args): void
{
    app()->rest()->registerRoute($namespace, $route, $args);
}

function rest_url(string $path = ''): string
{
    return '/api/' . trim($path, '/');
}

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = PHP_INT_MAX): void
{
    app()->hooks()->addAction($hook, $callback, $priority, $acceptedArgs);
}

function do_action(string $hook, mixed ...$args): void
{
    app()->hooks()->doAction($hook, ...$args);
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    return app()->hooks()->removeAction($hook, $callback, $priority);
}

function has_action(string $hook, ?callable $callback = null): bool|int
{
    return app()->hooks()->hasAction($hook, $callback);
}

function did_action(string $hook): int
{
    return app()->hooks()->didAction($hook);
}

function doing_action(?string $hook = null): bool
{
    return app()->hooks()->doingAction($hook);
}

function current_action(): ?string
{
    return app()->hooks()->currentAction();
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = PHP_INT_MAX): void
{
    app()->hooks()->addFilter($hook, $callback, $priority, $acceptedArgs);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return app()->hooks()->applyFilters($hook, $value, ...$args);
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    return app()->hooks()->removeFilter($hook, $callback, $priority);
}

function has_filter(string $hook, ?callable $callback = null): bool|int
{
    return app()->hooks()->hasFilter($hook, $callback);
}

function doing_filter(?string $hook = null): bool
{
    return app()->hooks()->doingFilter($hook);
}

function current_filter(): ?string
{
    return app()->hooks()->currentFilter();
}

function enqueue_admin_style(string $href): void
{
    add_filter('admin.styles', static function (array $styles) use ($href): array {
        $styles[] = $href;

        return array_values(array_unique($styles));
    });
}

function enqueue_admin_script(string $src, array $attributes = []): void
{
    add_filter('admin.scripts', static function (array $scripts) use ($src, $attributes): array {
        $scripts[] = [
            'src' => $src,
            'attributes' => $attributes,
        ];

        return $scripts;
    });
}

function kp_admin_template(string $template, array $data = []): string
{
    return (new Kivopress\Admin\AdminTemplates(app()))->render($template, $data);
}

function kp_admin_template_part(string $template, array $data = []): void
{
    echo kp_admin_template($template, $data);
}

function register_admin_menu(array $item): void
{
    app()->adminMenu()->add($item);
}

function register_admin_submenu(string $parent, array $item): void
{
    $item['parent'] = $parent;
    register_admin_menu($item);
}

function add_menu_page(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable|string|null $callback = null, string $icon = 'radio_button_unchecked', int $position = 100): void
{
    register_admin_menu([
        'href' => str_starts_with($menuSlug, '/') ? $menuSlug : '/admin/' . trim($menuSlug, '/'),
        'label' => $menuTitle ?: $pageTitle,
        'capability' => $capability,
        'icon' => $icon,
        'position' => $position,
        'callback' => $callback,
    ]);
}

function add_submenu_page(string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable|string|null $callback = null, int $position = 100): void
{
    register_admin_submenu(str_starts_with($parentSlug, '/') ? $parentSlug : '/admin/' . trim($parentSlug, '/'), [
        'href' => str_starts_with($menuSlug, '/') ? $menuSlug : '/admin/' . trim($menuSlug, '/'),
        'label' => $menuTitle ?: $pageTitle,
        'capability' => $capability,
        'position' => $position,
        'callback' => $callback,
    ]);
}

function register_admin_bar_item(array $item): void
{
    add_filter('admin_bar.items', static function (array $items) use ($item): array {
        $items[] = $item;

        return $items;
    }, (int) ($item['hook_priority'] ?? 10));
}

function register_nav_menu(string $slug, string $label, array $config = []): void
{
    app()->menus()->registerLocation($slug, $label, $config);
}

function register_nav_menus(array $locations): void
{
    foreach ($locations as $slug => $label) {
        if (is_array($label)) {
            register_nav_menu((string) $slug, (string) ($label['label'] ?? $slug), $label);
            continue;
        }

        register_nav_menu((string) $slug, (string) $label);
    }
}

function kp_nav_menu(array $args = []): string
{
    $location = (string) ($args['theme_location'] ?? $args['location'] ?? 'primary');

    return app()->menus()->render($location, $args);
}

function add_admin_css(string $css): void
{
    add_filter('admin.inline_css', static fn (string $current): string => trim($current . "\n" . $css));
}

function register_content_type(string $name, array $config): void
{
    app()->content()->registerType($name, $config);
}

function register_fields(string $contentType, array $fields): void
{
    app()->content()->registerFields($contentType, $fields);
}

function register_meta_box(string $contentType, string $id, array $box): void
{
    $contentType = strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $contentType));

    add_filter('content.meta_boxes', static function (array $boxes, array $schema, ?array $item, mixed $view = null) use ($contentType, $id, $box): array {
        if (($schema['name'] ?? '') !== $contentType) {
            return $boxes;
        }

        $html = '';

        if (isset($box['callback']) && is_callable($box['callback'])) {
            $html = (string) $box['callback']($item, $schema, $view);
        } else {
            $html = (string) ($box['html'] ?? '');
        }

        $boxes[] = [
            'id' => $id,
            'title' => (string) ($box['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $id))),
            'html' => $html,
            'context' => (string) ($box['context'] ?? 'normal'),
            'priority' => (int) ($box['priority'] ?? 10),
            'class' => (string) ($box['class'] ?? ''),
        ];

        return $boxes;
    });
}

function register_taxonomy(string $name, array $config): void
{
    app()->content()->registerTaxonomy($name, $config);
}

function add_content_list_column(string $contentType, string $key, string $label, ?callable $callback = null, int $priority = 10): void
{
    $contentType = sanitize_slug(str_replace('-', '_', $contentType));
    $key = sanitize_slug(str_replace('-', '_', $key));

    add_filter('content.list_columns', static function (array $columns, array $schema) use ($contentType, $key, $label): array {
        if (($schema['name'] ?? '') === $contentType) {
            $columns[$key] = $label;
        }

        return $columns;
    }, $priority, 2);

    if ($callback) {
        add_filter('content.list_column', static function (mixed $value, string $column, array $item, array $schema, mixed $view = null) use ($contentType, $key, $callback): mixed {
            if (($schema['name'] ?? '') === $contentType && $column === $key) {
                return $callback($item, $schema, $view);
            }

            return $value;
        }, $priority, 5);
    }
}

function taxonomy_terms(string $taxonomy): array
{
    return app()->content()->terms($taxonomy);
}

function content_terms(array|int $content, ?string $taxonomy = null): array
{
    $id = is_array($content) ? (int) ($content['id'] ?? 0) : $content;

    return app()->content()->termsForContent($id, $taxonomy);
}

function content_url(array $content): string
{
    return app()->theme()->contentUrl($content);
}

function kp_create_page(array $data, ?int $authorId = null): array
{
    return app()->content()->createPage($data, $authorId);
}

function kp_ensure_page(array $data, ?int $authorId = null, array $options = []): array
{
    return app()->content()->ensurePage($data, $authorId, $options);
}

function kp_get_content_meta(int $contentId, string $key = '', bool $single = false): mixed
{
    return Kivopress\Meta::getContent($contentId, $key, $single);
}

function kp_add_content_meta(int $contentId, string $key, mixed $value, bool $unique = false): int|false
{
    return Kivopress\Meta::addContent($contentId, $key, $value, $unique);
}

function kp_update_content_meta(int $contentId, string $key, mixed $value, mixed $previous = null): int|bool
{
    return Kivopress\Meta::updateContent($contentId, $key, $value, $previous);
}

function kp_delete_content_meta(int $contentId, string $key, mixed $value = null): bool
{
    return Kivopress\Meta::deleteContent($contentId, $key, $value);
}

function kp_get_user_meta(int $userId, string $key = '', bool $single = false): mixed
{
    return Kivopress\Meta::getUser($userId, $key, $single);
}

function kp_add_user_meta(int $userId, string $key, mixed $value, bool $unique = false): int|false
{
    return Kivopress\Meta::addUser($userId, $key, $value, $unique);
}

function kp_update_user_meta(int $userId, string $key, mixed $value, mixed $previous = null): int|bool
{
    return Kivopress\Meta::updateUser($userId, $key, $value, $previous);
}

function kp_delete_user_meta(int $userId, string $key, mixed $value = null): bool
{
    return Kivopress\Meta::deleteUser($userId, $key, $value);
}

function kp_get_transient(string $key): mixed
{
    return Kivopress\Meta::getTransient($key);
}

function kp_set_transient(string $key, mixed $value, int $expiration = 0): bool
{
    return Kivopress\Meta::setTransient($key, $value, $expiration);
}

function kp_delete_transient(string $key): bool
{
    return Kivopress\Meta::deleteTransient($key);
}

function kp_serialize(mixed $data): ?string
{
    return Kivopress\Meta::serialize($data);
}

function kp_unserialize(mixed $data): mixed
{
    return Kivopress\Meta::unserialize($data);
}

function kp_is_serialized(mixed $data): bool
{
    return is_string($data) && Kivopress\Meta::isSerialized($data);
}

function kp_document_title(string $title, mixed $content = null, array $site = []): string
{
    return (string) apply_filters('theme.title', $title, $content, $site);
}

function kp_head(mixed $content = null, array $site = []): void
{
    echo apply_filters('theme.head', '', $content, $site);
    do_action('theme.head', $content, $site);
}

function kp_footer(mixed $content = null, array $site = []): void
{
    echo apply_filters('theme.footer', '', $content, $site);
    do_action('theme.footer', $content, $site);
}

function kp_body_open(mixed $content = null, array $site = []): void
{
    do_action('theme.body_open', $content, $site);
}

function kp_before_header(mixed $content = null, array $site = []): void
{
    do_action('theme.before_header', $content, $site);
}

function kp_after_header(mixed $content = null, array $site = []): void
{
    do_action('theme.after_header', $content, $site);
}

function kp_before_main(mixed $context = null): void
{
    do_action('theme.before_main', $context);
}

function kp_after_main(mixed $context = null): void
{
    do_action('theme.after_main', $context);
}

function kp_before_content(mixed $content = null, string $context = 'content'): void
{
    do_action('theme.before_content', $content, $context);
}

function kp_after_content(mixed $content = null, string $context = 'content'): void
{
    do_action('theme.after_content', $content, $context);
}

function kp_content(mixed $content, string $field = 'body', string $context = 'content'): void
{
    echo kp_get_content($content, $field, $context);
}

function kp_get_content(mixed $content, string $field = 'body', string $context = 'content'): string
{
    $item = is_array($content) ? $content : null;
    $html = is_array($content) ? (string) ($content[$field] ?? '') : (string) $content;

    do_action('theme.before_content_render', $item, $field, $context);
    $html = (string) apply_filters('content.body', $html, $item);
    $html = (string) apply_filters('theme.content', $html, $item, $field, $context);
    do_action('theme.after_content_render', $item, $field, $context, $html);

    return $html;
}

function kp_before_loop(string $context, array $items = []): void
{
    do_action('theme.before_loop', $context, $items);
}

function kp_after_loop(string $context, array $items = []): void
{
    do_action('theme.after_loop', $context, $items);
}

function kp_before_loop_item(array $item, string $context = 'loop'): void
{
    do_action('theme.before_loop_item', $item, $context);
}

function kp_after_loop_item(array $item, string $context = 'loop'): void
{
    do_action('theme.after_loop_item', $item, $context);
}

function kp_before_footer(mixed $content = null, array $site = []): void
{
    do_action('theme.before_footer', $content, $site);
}

function kp_after_footer(mixed $content = null, array $site = []): void
{
    do_action('theme.after_footer', $content, $site);
}

function option(string $key, mixed $default = null): mixed
{
    return app()->db()->getOption($key, $default);
}

function set_option(string $key, mixed $value): void
{
    app()->db()->setOption($key, $value);
}

function schedule_event(string $hook, int $intervalSeconds, ?callable $callback = null, array $args = []): void
{
    app()->scheduler()->schedule($hook, $intervalSeconds, $callback, $args);
}

function plugin(array $metadata): void
{
    app()->plugins()->registerMetadata($metadata);
}

function register_activation_hook(string $file, callable $callback): void
{
    app()->plugins()->registerActivationHook($file, $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    app()->plugins()->registerDeactivationHook($file, $callback);
}

function e(mixed $value): string
{
    return esc_html($value);
}

function esc_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(mixed $value): string
{
    return esc_html($value);
}

function esc_textarea(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(mixed $value): string
{
    $url = trim((string) $value);

    if ($url === '') {
        return '';
    }

    if (preg_match('/^\s*javascript:/i', $url)) {
        return '';
    }

    return esc_attr($url);
}

function sanitize_text_field(mixed $value): string
{
    $value = strip_tags((string) $value);
    $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?: '';

    return trim($value);
}

function sanitize_textarea_field(mixed $value): string
{
    $value = strip_tags((string) $value);
    $value = preg_replace("/[\r\t ]+/", ' ', $value) ?: '';
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?: '';

    return trim($value);
}

function sanitize_email(mixed $value): string
{
    return (string) filter_var(trim((string) $value), FILTER_SANITIZE_EMAIL);
}

function is_email(mixed $value): string|false
{
    $email = filter_var((string) $value, FILTER_VALIDATE_EMAIL);

    return is_string($email) ? $email : false;
}

function kp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('kp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function kp_csrf_field(string $name = '_csrf', bool $display = true): string
{
    $field = '<input type="hidden" name="' . e($name) . '" value="' . e(app()->auth()->csrfToken()) . '">';

    if ($display) {
        echo $field;
    }

    return $field;
}

function kp_verify_csrf(mixed $token): bool
{
    return app()->auth()->validCsrf(is_string($token) ? $token : null);
}

function kp_mail(string $to, string $subject, string $message, array|string $headers = ''): bool
{
    $headers = is_array($headers) ? implode("\r\n", $headers) : $headers;

    try {
        return mail($to, $subject, $message, $headers);
    } catch (\Throwable) {
        return false;
    }
}

function status_header(int $code, string $description = ''): void
{
    http_response_code($code);
}

function is_user_logged_in(): bool
{
    return app()->auth()->user() !== null;
}

function sanitize_slug(mixed $value): string
{
    $slug = strtolower(trim((string) $value));
    $slug = preg_replace('/[^a-z0-9_-]+/i', '-', $slug) ?: '';

    return trim($slug, '-_');
}
