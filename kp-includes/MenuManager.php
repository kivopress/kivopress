<?php

declare(strict_types=1);

namespace Kivopress;

final class MenuManager
{
    private array $locations = [];

    public function __construct(private Database $db)
    {
    }

    public function registerLocation(string $slug, string $label, array $config = []): void
    {
        $slug = sanitize_slug($slug);

        if ($slug === '') {
            throw new \InvalidArgumentException('Menu location slug is required.');
        }

        $this->locations[$slug] = [
            'slug' => $slug,
            'label' => $label,
            'description' => (string) ($config['description'] ?? ''),
        ];
    }

    public function locations(): array
    {
        return apply_filters('nav_menu_locations', $this->locations);
    }

    public function all(): array
    {
        $menus = $this->store()['menus'] ?? [];

        usort($menus, fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return apply_filters('nav_menus', $menus);
    }

    public function menu(int|string|null $id): ?array
    {
        $id = (string) $id;

        if ($id === '') {
            return null;
        }

        return $this->store()['menus'][$id] ?? null;
    }

    public function create(string $name, array $items = []): array
    {
        $store = $this->store();
        $id = (int) ($store['next_id'] ?? 1);
        $name = trim($name) !== '' ? trim($name) : 'Menu ' . $id;
        $menu = [
            'id' => (string) $id,
            'name' => $name,
            'items' => $this->normalizeItems($items),
        ];

        $store['menus'][(string) $id] = $menu;
        $store['next_id'] = $id + 1;
        $this->saveStore($store);
        do_action('nav_menu_created', $menu);

        return $menu;
    }

    public function saveMenu(int|string $id, string $name, array $items): array
    {
        $store = $this->store();
        $id = (string) $id;

        if (!isset($store['menus'][$id])) {
            return $this->create($name, $items);
        }

        $store['menus'][$id]['name'] = trim($name) !== '' ? trim($name) : (string) $store['menus'][$id]['name'];
        $store['menus'][$id]['items'] = $this->normalizeItems($items);
        $this->saveStore($store);
        do_action('nav_menu_saved', $store['menus'][$id]);

        return $store['menus'][$id];
    }

    public function delete(int|string $id): void
    {
        $store = $this->store();
        $id = (string) $id;

        if (!isset($store['menus'][$id])) {
            return;
        }

        unset($store['menus'][$id]);

        foreach (($store['locations'] ?? []) as $location => $menuId) {
            if ((string) $menuId === $id) {
                unset($store['locations'][$location]);
            }
        }

        $this->saveStore($store);
        do_action('nav_menu_deleted', $id);
    }

    public function locationAssignments(): array
    {
        $locations = $this->store()['locations'] ?? [];

        return is_array($locations) ? $locations : [];
    }

    public function saveLocations(array $locations): void
    {
        $store = $this->store();
        $available = $this->locations();
        $menus = $store['menus'] ?? [];
        $assignments = [];

        foreach ($available as $location => $_config) {
            $menuId = (string) ($locations[$location] ?? '');

            if ($menuId !== '' && isset($menus[$menuId])) {
                $assignments[(string) $location] = $menuId;
            }
        }

        $store['locations'] = $assignments;
        $this->saveStore($store);
        do_action('nav_menu_locations_saved', $assignments);
    }

    public function items(string $location): array
    {
        $store = $this->store();
        $menuId = (string) (($store['locations'] ?? [])[$location] ?? '');
        $items = $menuId !== '' ? (($store['menus'][$menuId]['items'] ?? []) ?: []) : [];

        if ($items === []) {
            $items = $this->legacyMenus()[$location] ?? [];
        }

        if (!is_array($items)) {
            $items = [];
        }

        return apply_filters('nav_menu_items', $items, $location);
    }

    public function save(string $location, array $items): void
    {
        $store = $this->store();
        $menuId = (string) (($store['locations'] ?? [])[$location] ?? '');

        if ($menuId === '' || !isset($store['menus'][$menuId])) {
            $menu = $this->create((string) ($this->locations()[$location]['label'] ?? ucfirst($location)), $items);
            $store = $this->store();
            $store['locations'][$location] = $menu['id'];
            $this->saveStore($store);

            return;
        }

        $this->saveMenu($menuId, (string) $store['menus'][$menuId]['name'], $items);
    }

    public function render(string $location, array $args = []): string
    {
        $items = $this->items($location);

        if ($items === []) {
            return '';
        }

        $class = trim((string) ($args['class'] ?? 'kp-menu'));
        $menuClass = trim((string) ($args['menu_class'] ?? ''));
        $ariaLabel = trim((string) ($args['aria_label'] ?? ''));
        $navAttributes = 'class="' . e($class) . '" data-menu-location="' . e($location) . '"';

        if ($ariaLabel !== '') {
            $navAttributes .= ' aria-label="' . e($ariaLabel) . '"';
        }

        $html = '<nav ' . $navAttributes . '><ul' . ($menuClass !== '' ? ' class="' . e($menuClass) . '"' : '') . '>';

        foreach ($items as $item) {
            if ((string) ($item['parent'] ?? '') !== '') {
                continue;
            }

            $html .= $this->renderItem($item, $items);
        }

        $html .= '</ul></nav>';

        return apply_filters('nav_menu_html', $html, $location, $args, $items);
    }

    private function store(): array
    {
        $store = $this->db->getOption('nav_menu_store', []);

        if (!is_array($store) || !isset($store['menus'])) {
            $store = [
                'next_id' => 1,
                'menus' => [],
                'locations' => [],
            ];
        }

        $store['menus'] = is_array($store['menus'] ?? null) ? $store['menus'] : [];
        $store['locations'] = is_array($store['locations'] ?? null) ? $store['locations'] : [];
        $store['next_id'] = max(1, (int) ($store['next_id'] ?? 1));

        return $store;
    }

    private function saveStore(array $store): void
    {
        $this->db->setOption('nav_menu_store', [
            'next_id' => max(1, (int) ($store['next_id'] ?? 1)),
            'menus' => is_array($store['menus'] ?? null) ? $store['menus'] : [],
            'locations' => is_array($store['locations'] ?? null) ? $store['locations'] : [],
        ]);
    }

    private function legacyMenus(): array
    {
        $menus = $this->db->getOption('nav_menus', []);

        return is_array($menus) ? $menus : [];
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));

            if ($label === '' || $url === '') {
                continue;
            }

            $id = sanitize_slug((string) ($item['id'] ?? ''));

            if ($id === '') {
                $id = 'menu-item-' . substr(sha1($label . '|' . $url . '|' . $index . '|' . microtime(true)), 0, 12);
            }

            $normalized[] = [
                'id' => $id,
                'label' => $label,
                'url' => $url,
                'parent' => sanitize_slug((string) ($item['parent'] ?? '')),
                'target' => in_array((string) ($item['target'] ?? ''), ['_blank', '_self'], true) ? (string) $item['target'] : '',
                'class' => trim((string) ($item['class'] ?? '')),
                'type' => trim((string) preg_replace('/[^a-zA-Z0-9_ -]/', '', (string) ($item['type'] ?? 'Custom Link'))) ?: 'Custom Link',
                'object_type' => sanitize_slug((string) ($item['object_type'] ?? '')),
                'object_id' => (int) ($item['object_id'] ?? 0),
                'position' => (int) ($item['position'] ?? ($index + 1) * 10),
            ];
        }

        usort($normalized, fn (array $a, array $b): int => $a['position'] <=> $b['position'] ?: strcmp($a['label'], $b['label']));
        $ids = [];

        foreach ($normalized as $index => $item) {
            $ids[(string) $item['id']] = $index;
        }

        foreach ($normalized as $index => $item) {
            $parent = (string) ($item['parent'] ?? '');
            $seen = [(string) $item['id'] => true];

            while ($parent !== '') {
                if (!isset($ids[$parent]) || isset($seen[$parent])) {
                    $normalized[$index]['parent'] = '';
                    break;
                }

                $seen[$parent] = true;
                $parent = (string) ($normalized[$ids[$parent]]['parent'] ?? '');
            }
        }

        return $normalized;
    }

    private function renderItem(array $item, array $all): string
    {
        $children = array_values(array_filter($all, static fn (array $child): bool => (string) ($child['parent'] ?? '') === (string) ($item['id'] ?? '')));
        $target = ($item['target'] ?? '') !== '' ? ' target="' . e($item['target']) . '" rel="noopener"' : '';
        $class = trim('menu-item ' . (string) ($item['class'] ?? '') . ($children ? ' menu-item-has-children' : ''));
        $html = '<li class="' . e($class) . '"><a href="' . e((string) $item['url']) . '"' . $target . '>' . e((string) $item['label']) . '</a>';

        if ($children) {
            $html .= '<ul class="sub-menu">';

            foreach ($children as $child) {
                $html .= $this->renderItem($child, $all);
            }

            $html .= '</ul>';
        }

        return $html . '</li>';
    }
}
