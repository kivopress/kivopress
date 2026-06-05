<?php

declare(strict_types=1);

namespace Kivopress;

final class AdminMenu
{
    private array $customItems = [];
    private bool $bootstrapped = false;

    public function __construct(private App $app)
    {
    }

    public function add(array $item): void
    {
        $this->customItems[] = $item;
    }

    public function addMenuPage(string $label, string $href, string $capability = 'read', string $icon = 'radio_button_unchecked', int $position = 100): void
    {
        $this->add(compact('label', 'href', 'capability', 'icon', 'position'));
    }

    public function addSubmenuPage(string $parent, string $label, string $href, string $capability = 'read', int $position = 100): void
    {
        $this->add(compact('parent', 'label', 'href', 'capability', 'position'));
    }

    public function items(): array
    {
        if (!$this->bootstrapped) {
            $this->app->hooks()->doAction('admin_menu', $this);
            $this->bootstrapped = true;
        }

        $items = array_merge($this->baseItems(), $this->customItems);
        $items = $this->filterByCapability($items);

        return apply_filters('admin.nav', $items);
    }

    public function findByHref(string $href): ?array
    {
        $href = $this->normalizeHref($href);

        foreach ($this->flatten($this->items()) as $item) {
            if ($this->normalizeHref((string) ($item['href'] ?? '')) === $href) {
                return $item;
            }
        }

        return null;
    }

    private function baseItems(): array
    {
        $items = [
            ['href' => '/admin', 'label' => 'Dashboard', 'icon' => 'dashboard', 'position' => 10],
        ];
        $nestedTaxonomies = [];
        $content = $this->app->content();
        $auth = $this->app->auth();

        foreach ($content->types() as $type) {
            if (!($type['show_admin'] ?? true)) {
                continue;
            }

            if (!$auth->can('edit_' . $type['api_slug'])) {
                continue;
            }

            $children = [[
                'href' => '/admin/content/' . $type['name'] . '/new',
                'label' => 'Add New ' . ($type['singular_label'] ?? rtrim((string) $type['label'], 's')),
                'position' => 10,
            ]];

            foreach ($content->taxonomiesFor($type['name']) as $taxonomy) {
                if (!($taxonomy['show_admin'] ?? true) || !$this->canManageTaxonomy($taxonomy) || isset($nestedTaxonomies[$taxonomy['name']])) {
                    continue;
                }

                $nestedTaxonomies[$taxonomy['name']] = true;
                $children[] = [
                    'href' => '/admin/taxonomies/' . $taxonomy['name'],
                    'label' => $taxonomy['label'],
                    'position' => $taxonomy['hierarchical'] ? 20 : 30,
                ];
            }

            $items[] = [
                'href' => '/admin/content/' . $type['name'],
                'label' => $type['label'],
                'icon' => $type['name'] === 'page' ? 'description' : 'article',
                'position' => $type['name'] === 'post' ? 20 : 30,
                'children' => $children,
            ];
        }

        foreach ($content->taxonomies() as $taxonomy) {
            if (isset($nestedTaxonomies[$taxonomy['name']]) || !($taxonomy['show_admin'] ?? true) || !$this->canManageTaxonomy($taxonomy)) {
                continue;
            }

            $items[] = [
                'href' => '/admin/taxonomies/' . $taxonomy['name'],
                'label' => $taxonomy['label'],
                'icon' => $taxonomy['hierarchical'] ? 'category' : 'tag',
                'position' => 35,
            ];
        }

        if ($auth->can('upload_media') || $auth->can('manage_media')) {
            $items[] = ['href' => '/admin/media', 'label' => 'Media', 'icon' => 'perm_media', 'position' => 40];
        }

        if ($auth->can('manage_extensions')) {
            $items[] = ['href' => '/admin/themes', 'label' => 'Themes', 'icon' => 'palette', 'position' => 50, 'children' => [
                ['href' => '/admin/menus', 'label' => 'Menus', 'capability' => 'manage_settings', 'position' => 20],
            ]];
            $items[] = ['href' => '/admin/plugins', 'label' => 'Plugins', 'icon' => 'extension', 'position' => 60];
        }

        if ($auth->can('manage_users')) {
            $items[] = ['href' => '/admin/users', 'label' => 'Users', 'icon' => 'group', 'position' => 70];
        }

        if ($auth->can('manage_settings')) {
            $items[] = ['href' => '/admin/rest-api', 'label' => 'REST API', 'icon' => 'api', 'position' => 80];
            $items[] = ['href' => '/admin/tools', 'label' => 'Tools', 'icon' => 'build', 'position' => 85, 'children' => [
                ['href' => '/admin/tools/errors', 'label' => 'Error Logs', 'position' => 10],
            ]];
            $items[] = ['href' => '/admin/settings', 'label' => 'Settings', 'icon' => 'settings', 'position' => 90, 'children' => [
                ['href' => '/admin/settings/permalinks', 'label' => 'Permalinks', 'position' => 10],
            ]];
        }

        return $items;
    }

    private function canManageTaxonomy(array $taxonomy): bool
    {
        $capability = (string) ($taxonomy['manage_capability'] ?? 'manage_settings');

        return $this->app->auth()->can($capability);
    }

    private function filterByCapability(array $items): array
    {
        $filtered = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $capability = (string) ($item['capability'] ?? '');

            if ($capability !== '' && !$this->app->auth()->can($capability)) {
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->filterByCapability($item['children']);
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private function flatten(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $children = is_array($item['children'] ?? null) ? $item['children'] : [];
            unset($item['children']);
            $flat[] = $item;
            array_push($flat, ...$this->flatten($children));
        }

        return $flat;
    }

    private function normalizeHref(string $href): string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }
}
