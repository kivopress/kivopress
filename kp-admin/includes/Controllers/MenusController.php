<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class MenusController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->guardCapability('manage_settings')) {
            return $redirect;
        }

        add_filter('admin.styles', static function (array $styles): array {
            $styles[] = '/kp-admin/assets/kivopress-menus.css';

            return $styles;
        });

        $tab = ($_GET['tab'] ?? '') === 'locations' ? 'locations' : 'edit';
        $menus = $this->app->menus()->all();
        $selected = $this->selectedMenu($menus, $_GET['menu'] ?? null);
        $html = $this->tabs($tab);
        $html .= $tab === 'locations'
            ? $this->locationsView($menus)
            : $this->editView($menus, $selected);
        $html .= $this->script();

        return $this->view->layout('Menus', $html);
    }

    public function save(): Response
    {
        if ($redirect = $this->guardPostCapability('manage_settings')) {
            return $redirect;
        }

        $mode = (string) ($_POST['mode'] ?? 'save_menu');

        if ($mode === 'save_locations') {
            $locations = is_array($_POST['locations'] ?? null) ? $_POST['locations'] : [];
            $this->app->menus()->saveLocations($locations);
            $this->auth->flash('notice', 'Menu locations saved.');

            return Response::redirect('/admin/menus?tab=locations');
        }

        if ($mode === 'delete_menu') {
            $id = (string) ($_POST['menu_id'] ?? '');
            $this->app->menus()->delete($id);
            $this->auth->flash('notice', 'Menu deleted.');

            return Response::redirect('/admin/menus');
        }

        $name = trim((string) ($_POST['menu_name'] ?? ''));
        $items = $this->postedItems();

        if ($mode === 'create_menu' || (string) ($_POST['menu_id'] ?? '') === '') {
            $menu = $this->app->menus()->create($name, $items);
        } else {
            $menu = $this->app->menus()->saveMenu((string) $_POST['menu_id'], $name, $items);
        }

        $locations = $this->app->menus()->locationAssignments();

        foreach ($this->app->menus()->locations() as $location => $_config) {
            if (isset($_POST['assign_locations'][$location])) {
                $locations[$location] = $menu['id'];
            } elseif (($locations[$location] ?? '') === $menu['id']) {
                unset($locations[$location]);
            }
        }

        $this->app->menus()->saveLocations($locations);
        $this->auth->flash('notice', 'Menu saved.');

        return Response::redirect('/admin/menus?menu=' . rawurlencode((string) $menu['id']));
    }

    private function editView(array $menus, ?array $selected): string
    {
        $intro = '<div class="kp-menu-selector">
            <a class="kp-button kp-button-secondary" href="/" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'Manage with Live Preview</a>
        </div>';

        if ($menus !== []) {
            $intro = '<form action="/admin/menus" method="get" class="kp-menu-select-form">
                <label>Select a menu to edit:
                    <select name="menu">' . $this->menuOptions($menus, (string) ($selected['id'] ?? '')) . '</select>
                </label>
                <button>Select</button>
                <span class="kp-menu-create-link">or <a href="/admin/menus?new=1">create a new menu</a>. Do not forget to save your changes!</span>
            </form>' . $intro;
        }

        $selected = isset($_GET['new']) ? null : $selected;
        $menuForm = $selected ? $this->menuForm($selected, false) : $this->menuForm([
            'id' => '',
            'name' => '',
            'items' => [],
        ], true);

        return $intro . '<div class="kp-menu-workspace">
            <section>
                <h2>Add menu items</h2>
                ' . $this->addItemsPanel((bool) $selected) . '
            </section>
            <section>
                <h2>Menu structure</h2>
                ' . $menuForm . '
            </section>
        </div>';
    }

    private function locationsView(array $menus): string
    {
        $locations = $this->app->menus()->locations();
        $assigned = $this->app->menus()->locationAssignments();
        $rows = '';

        foreach ($locations as $slug => $location) {
            $rows .= '<tr>
                <td><strong>' . \e((string) ($location['label'] ?? $slug)) . '</strong><p class="kp-field-help">' . \e((string) ($location['description'] ?? '')) . '</p></td>
                <td><select name="locations[' . \e((string) $slug) . ']"><option value="">- Select -</option>' . $this->menuOptions($menus, (string) ($assigned[$slug] ?? '')) . '</select></td>
                <td>' . (($assigned[$slug] ?? '') !== '' ? '<a href="/admin/menus?menu=' . \e((string) $assigned[$slug]) . '">Edit</a> <span class="kp-muted">|</span> ' : '') . '<a href="/admin/menus?new=1">Use new menu</a></td>
            </tr>';
        }

        return $this->view->form('/admin/menus', '
            <input type="hidden" name="mode" value="save_locations">
            <p>Your theme supports ' . count($locations) . ' menus. Select which menu appears in each location.</p>
            <div class="kp-table-wrap kp-menu-locations-table"><table>
                <thead><tr><th>Menu Location</th><th>Assigned Menu</th><th></th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table></div>
            <button>' . $this->view->icon('save') . 'Save Changes</button>
        ', true, 'kp-form kp-menu-locations-form');
    }

    private function tabs(string $active): string
    {
        return '<div class="kp-tabs">
            <a class="' . ($active === 'edit' ? 'is-active' : '') . '" href="/admin/menus">Edit Menus</a>
            <a class="' . ($active === 'locations' ? 'is-active' : '') . '" href="/admin/menus?tab=locations">Manage Locations</a>
        </div>';
    }

    private function menuForm(array $menu, bool $creating): string
    {
        $items = array_values((array) ($menu['items'] ?? []));
        $locations = $this->app->menus()->locations();
        $assigned = $this->app->menus()->locationAssignments();
        $menuId = (string) ($menu['id'] ?? '');
        $assignmentChecks = '';

        foreach ($locations as $slug => $location) {
            $checked = ($assigned[$slug] ?? '') === $menuId;
            $assignmentChecks .= '<label class="kp-check"><input type="checkbox" name="assign_locations[' . \e((string) $slug) . ']" value="1" ' . ($checked ? 'checked' : '') . '> ' . \e((string) ($location['label'] ?? $slug)) . '</label>';
        }

        $body = '<input type="hidden" name="mode" value="' . ($creating ? 'create_menu' : 'save_menu') . '">
            <input type="hidden" name="menu_id" value="' . \e($menuId) . '">
            <div class="kp-menu-name-row">
                <label>Menu Name <input name="menu_name" value="' . \e((string) ($menu['name'] ?? '')) . '" required></label>
            </div>';

        if ($creating) {
            $body .= '<p>Give your menu a name, then click Create Menu.</p>';
        } else {
            $body .= '<p>Drag the items into the order you prefer. Click the arrow on the right of the item to reveal additional configuration options.</p>
                <div class="kp-menu-bulk"><label class="kp-check"><input type="checkbox" data-kp-menu-select-all> Bulk Select</label></div>
                <ol class="kp-menu-structure-list" data-kp-menu-list>' . $this->menuItemRows($items) . '</ol>';
        }

        $body .= '<hr><h3>Menu Settings</h3>
            <div class="kp-menu-settings">
                <span>Auto add pages</span><label class="kp-check"><input type="checkbox" disabled> Automatically add new top-level pages to this menu</label>
                <span>Menu location</span><div>' . $assignmentChecks . '</div>
            </div>
            <div class="kp-menu-savebar">
                <button>' . ($creating ? 'Create Menu' : 'Save Menu') . '</button>
                ' . (!$creating ? '<button class="kp-button kp-button-danger" type="submit" name="mode" value="delete_menu" formnovalidate>Delete Menu</button>' : '') . '
            </div>';

        return $this->view->form('/admin/menus', $body, true, 'kp-form kp-menu-structure-form');
    }

    private function addItemsPanel(bool $enabled): string
    {
        $disabled = $enabled ? '' : ' disabled';
        $panels = [
            'Pages' => $this->contentChoices('page', 'Page', $disabled),
            'Posts' => $this->contentChoices('post', 'Post', $disabled),
            'Custom Links' => $this->customLinkChoices($disabled),
            'Categories' => $this->termChoices('category', 'Category', $disabled),
            'Tags' => $this->termChoices('post_tag', 'Tag', $disabled),
        ];
        $html = '';

        foreach ($panels as $title => $content) {
            $html .= '<details class="kp-menu-add-box" ' . ($title === 'Pages' ? 'open' : '') . '>
                <summary>' . \e($title) . '</summary>
                <div>' . $content . '</div>
            </details>';
        }

        if (!$enabled) {
            $html = '<p class="kp-notice">Create your first menu below.</p>' . $html;
        }

        return $html;
    }

    private function contentChoices(string $type, string $label, string $disabled): string
    {
        $recent = $this->content->all($type, ['limit' => 10, 'orderby' => 'updated_at', 'order' => 'desc', 'include_drafts' => true]);
        $items = $this->content->all($type, ['limit' => 100, 'orderby' => 'title', 'order' => 'asc', 'include_drafts' => true]);

        return '<div class="kp-menu-add-tabs" role="tablist">
                <button type="button" class="is-active" data-kp-menu-tab="recent">Most Recent</button>
                <button type="button" data-kp-menu-tab="all">View All</button>
                <button type="button" data-kp-menu-tab="search">Search</button>
            </div>
            <div class="kp-menu-tab-panel is-active" data-kp-menu-panel="recent">' . $this->choiceList($recent, $type, $label, $disabled) . '</div>
            <div class="kp-menu-tab-panel" data-kp-menu-panel="all" hidden>' . $this->choiceList($items, $type, $label, $disabled) . '</div>
            <div class="kp-menu-tab-panel" data-kp-menu-panel="search" hidden>
                <input class="kp-menu-search" data-kp-menu-search placeholder="Search ' . \e(strtolower($label)) . 's">
                ' . $this->choiceList($items, $type, $label, $disabled) . '
            </div>
            <div class="kp-menu-add-actions"><label class="kp-check"><input type="checkbox" data-kp-panel-select-all' . $disabled . '> Select All</label><button type="button" data-kp-add-selected' . $disabled . '>Add to Menu</button></div>';
    }

    private function termChoices(string $taxonomy, string $label, string $disabled): string
    {
        $terms = [];

        try {
            $terms = $this->content->terms($taxonomy);
        } catch (\Throwable) {
            $terms = [];
        }

        $popular = $terms;
        usort($popular, fn (array $a, array $b): int => ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0)) ?: strcasecmp((string) $a['name'], (string) $b['name']));
        $popular = array_slice($popular, 0, 10);

        return '<div class="kp-menu-add-tabs" role="tablist">
                <button type="button" class="is-active" data-kp-menu-tab="popular">Most Used</button>
                <button type="button" data-kp-menu-tab="all">View All</button>
                <button type="button" data-kp-menu-tab="search">Search</button>
            </div>
            <div class="kp-menu-tab-panel is-active" data-kp-menu-panel="popular">' . $this->termChoiceList($popular, $taxonomy, $label, $disabled) . '</div>
            <div class="kp-menu-tab-panel" data-kp-menu-panel="all" hidden>' . $this->termChoiceList($terms, $taxonomy, $label, $disabled) . '</div>
            <div class="kp-menu-tab-panel" data-kp-menu-panel="search" hidden>
                <input class="kp-menu-search" data-kp-menu-search placeholder="Search ' . \e(strtolower($label)) . 's">
                ' . $this->termChoiceList($terms, $taxonomy, $label, $disabled) . '
            </div>
            <div class="kp-menu-add-actions"><label class="kp-check"><input type="checkbox" data-kp-panel-select-all' . $disabled . '> Select All</label><button type="button" data-kp-add-selected' . $disabled . '>Add to Menu</button></div>';
    }

    private function customLinkChoices(string $disabled): string
    {
        return '<label class="kp-menu-field">URL <input data-kp-custom-url placeholder="https://example.com/"' . $disabled . '></label>
            <label class="kp-menu-field">Link Text <input data-kp-custom-label placeholder="Menu item label"' . $disabled . '></label>
            <div class="kp-menu-add-actions"><span></span><button type="button" data-kp-add-custom' . $disabled . '>Add to Menu</button></div>';
    }

    private function choiceList(array $items, string $type, string $label, string $disabled): string
    {
        $html = '';

        foreach ($items as $item) {
            $html .= '<label class="kp-menu-choice"><input type="checkbox" data-kp-add-menu-item data-label="' . \e((string) $item['title']) . '" data-url="' . \e(\content_url($item)) . '" data-type="' . \e($label) . '" data-object-type="' . \e($type) . '" data-object-id="' . (int) $item['id'] . '"' . $disabled . '> <span>' . \e((string) $item['title']) . '</span></label>';
        }

        if ($items === []) {
            $html .= '<p class="kp-empty">No ' . \e(strtolower($label)) . ' items found.</p>';
        }

        return '<div class="kp-menu-choice-list">' . $html . '</div>';
    }

    private function termChoiceList(array $terms, string $taxonomy, string $label, string $disabled): string
    {
        $html = '';
        $base = $taxonomy === 'post_tag' ? 'tag' : 'category';

        foreach ($terms as $term) {
            $html .= '<label class="kp-menu-choice"><input type="checkbox" data-kp-add-menu-item data-label="' . \e((string) $term['name']) . '" data-url="/' . $base . '/' . \e((string) $term['slug']) . '/" data-type="' . \e($label) . '" data-object-type="' . \e($taxonomy) . '" data-object-id="' . (int) $term['id'] . '"' . $disabled . '> <span>' . \e((string) $term['name']) . '</span></label>';
        }

        if ($terms === []) {
            $html .= '<p class="kp-empty">No ' . \e(strtolower($label)) . ' items found.</p>';
        }

        return '<div class="kp-menu-choice-list">' . $html . '</div>';
    }

    private function menuItemRows(array $items): string
    {
        $rows = '';
        $children = [];
        $used = [];

        foreach ($items as $item) {
            $parent = (string) ($item['parent'] ?? '');
            $children[$parent][] = $item;
        }

        $render = function (string $parent, int $depth) use (&$render, &$rows, &$used, $children, $items): void {
            foreach ($children[$parent] ?? [] as $item) {
                $id = (string) ($item['id'] ?? '');

                if ($id !== '' && isset($used[$id])) {
                    continue;
                }

                $rowIndex = count($used);

                if ($id !== '') {
                    $used[$id] = true;
                }

                $rows .= $this->menuItemRow($item, $rowIndex, $items, $depth);

                if ($id !== '') {
                    $render($id, min(5, $depth + 1));
                }
            }
        };

        $render('', 0);

        foreach ($items as $index => $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id === '' || isset($used[$id])) {
                continue;
            }

            $used[$id] = true;
            $rows .= $this->menuItemRow($item, $index, $items, 0);
        }

        return $rows;
    }

    private function menuItemRow(array $item, int $index, array $all, int $depth = 0): string
    {
        $label = (string) ($item['label'] ?? '');
        $id = (string) ($item['id'] ?? ('menu-item-' . $index));
        $type = (string) ($item['type'] ?? 'Custom Link');

        return '<li class="kp-menu-item" data-kp-menu-item data-kp-item="' . \e($id) . '" draggable="true" style="--kp-menu-depth:' . max(0, min(5, $depth)) . '">
            <details>
                <summary><span class="kp-menu-item-title"><strong>' . \e($label) . '</strong><em>' . \e($type) . '</em></span><span class="kp-menu-item-actions"><button type="button" data-kp-menu-indent title="Make sub item">&rarr;</button><button type="button" data-kp-menu-outdent title="Move up one level">&larr;</button><span aria-hidden="true">&#9662;</span></span></summary>
                <div class="kp-menu-item-settings">
                    <input type="hidden" data-kp-item-id name="items[' . $index . '][id]" value="' . \e($id) . '">
                    <input type="hidden" name="items[' . $index . '][type]" value="' . \e($type) . '">
                    <input type="hidden" name="items[' . $index . '][object_type]" value="' . \e((string) ($item['object_type'] ?? '')) . '">
                    <input type="hidden" name="items[' . $index . '][object_id]" value="' . (int) ($item['object_id'] ?? 0) . '">
                    <label>URL <input name="items[' . $index . '][url]" value="' . \e((string) ($item['url'] ?? '')) . '"></label>
                    <label>Navigation Label <input data-kp-menu-label name="items[' . $index . '][label]" value="' . \e($label) . '"></label>
                    <div class="kp-menu-item-grid">
                        <label>Menu Parent <select data-kp-parent-select name="items[' . $index . '][parent]">' . $this->parentOptions($all, (string) ($item['parent'] ?? ''), $id) . '</select></label>
                        <label>Menu Order <input data-kp-position type="number" name="items[' . $index . '][position]" value="' . (int) ($item['position'] ?? (($index + 1) * 10)) . '"></label>
                    </div>
                    <div class="kp-menu-item-grid">
                        <label>Open Link In <select name="items[' . $index . '][target]"><option value="">Same tab</option><option value="_blank" ' . (($item['target'] ?? '') === '_blank' ? 'selected' : '') . '>New tab</option></select></label>
                        <label>CSS Classes <input name="items[' . $index . '][class]" value="' . \e((string) ($item['class'] ?? '')) . '"></label>
                    </div>
                    <p><a href="#" data-kp-remove-menu-item>Remove</a> <span class="kp-muted">|</span> <a href="#" data-kp-cancel-menu-item>Cancel</a></p>
                </div>
            </details>
        </li>';
    }

    private function selectedMenu(array $menus, mixed $value): ?array
    {
        $id = (string) $value;

        if ($id !== '') {
            foreach ($menus as $menu) {
                if ((string) ($menu['id'] ?? '') === $id) {
                    return $menu;
                }
            }
        }

        return $menus[0] ?? null;
    }

    private function menuOptions(array $menus, string $selected): string
    {
        $html = '';

        foreach ($menus as $menu) {
            $id = (string) ($menu['id'] ?? '');
            $html .= '<option value="' . \e($id) . '" ' . ($id === $selected ? 'selected' : '') . '>' . \e((string) ($menu['name'] ?? 'Menu')) . '</option>';
        }

        return $html;
    }

    private function parentOptions(array $items, string $selected, string $current): string
    {
        $html = '<option value="">No Parent</option>';

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $label = (string) ($item['label'] ?? '');

            if ($id === '' || $id === $current || $label === '') {
                continue;
            }

            $html .= '<option value="' . \e($id) . '" ' . ($selected === $id ? 'selected' : '') . '>' . \e($label) . '</option>';
        }

        return $html;
    }

    private function postedItems(): array
    {
        $items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];

        return array_map(fn (mixed $item): array => $this->sanitizeItem(is_array($item) ? $item : []), $items);
    }

    private function sanitizeItem(array $item): array
    {
        return [
            'id' => sanitize_slug((string) ($item['id'] ?? '')),
            'label' => trim((string) ($item['label'] ?? '')),
            'url' => $this->sanitizeUrl((string) ($item['url'] ?? '')),
            'parent' => sanitize_slug((string) ($item['parent'] ?? '')),
            'target' => in_array((string) ($item['target'] ?? ''), ['', '_blank'], true) ? (string) ($item['target'] ?? '') : '',
            'class' => preg_replace('/[^a-zA-Z0-9_ -]/', '', (string) ($item['class'] ?? '')) ?: '',
            'type' => trim((string) ($item['type'] ?? 'Custom Link')),
            'object_type' => sanitize_slug((string) ($item['object_type'] ?? '')),
            'object_id' => (int) ($item['object_id'] ?? 0),
            'position' => (int) ($item['position'] ?? 0),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('/^(https?:\/\/|\/|#|mailto:)/i', $url)) {
            return $url;
        }

        return '/' . ltrim($url, '/');
    }

    private function script(): string
    {
        return <<<'HTML'
<script>
(function () {
    var list = document.querySelector("[data-kp-menu-list]");

    function items() {
        return list ? Array.prototype.slice.call(list.children).filter(function (item) {
            return item.matches && item.matches("[data-kp-menu-item]");
        }) : [];
    }

    function escapeHtml(value) {
        return String(value || "").replace(/[&<>"\']/g, function (char) {
            return {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\'": "&#039;"}[char];
        });
    }

    function itemId(item) {
        var field = item.querySelector("[data-kp-item-id]");
        return field ? field.value : "";
    }

    function parentSelect(item) {
        return item.querySelector("[data-kp-parent-select]");
    }

    function parentValue(item) {
        var select = parentSelect(item);
        return select ? select.value : "";
    }

    function itemById(id) {
        return items().find(function (item) {
            return itemId(item) === id;
        }) || null;
    }

    function depthFor(item) {
        var depth = 0;
        var parent = parentValue(item);
        var seen = {};

        while (parent && !seen[parent] && depth < 6) {
            seen[parent] = true;
            var parentItem = itemById(parent);
            if (!parentItem) break;
            depth++;
            parent = parentValue(parentItem);
        }

        return depth;
    }

    function isDescendant(candidate, ancestorId) {
        var parent = parentValue(candidate);
        var seen = {};

        while (parent && !seen[parent]) {
            if (parent === ancestorId) return true;
            seen[parent] = true;
            var parentItem = itemById(parent);
            if (!parentItem) return false;
            parent = parentValue(parentItem);
        }

        return false;
    }

    function refreshParentSelects() {
        var all = items().map(function (item) {
            return {
                id: itemId(item),
                label: (item.querySelector("[data-kp-menu-label]") || {}).value || (item.querySelector("summary strong") || {}).textContent || "Menu item",
                item: item
            };
        }).filter(function (entry) {
            return entry.id;
        });

        items().forEach(function (item) {
            var select = parentSelect(item);
            if (!select) return;
            var current = select.value;
            var self = itemId(item);
            var options = ['<option value="">No Parent</option>'];

            all.forEach(function (entry) {
                if (entry.id === self || isDescendant(entry.item, self)) return;
                options.push('<option value="' + escapeHtml(entry.id) + '">' + escapeHtml(entry.label) + '</option>');
            });

            select.innerHTML = options.join("");
            select.value = all.some(function (entry) { return entry.id === current; }) && current !== self ? current : "";
        });
    }

    function reindex() {
        items().forEach(function (item, index) {
            item.querySelectorAll("[name^=\"items[\"]").forEach(function (field) {
                field.name = field.name.replace(/items\\[\\d+\\]/, "items[" + index + "]");
            });
            var position = item.querySelector("[data-kp-position]");
            if (position) position.value = (index + 1) * 10;
        });
    }

    function syncStructure() {
        items().forEach(function (item) {
            var select = parentSelect(item);
            var self = itemId(item);

            if (!select) return;

            if (select.value === self || (select.value && !itemById(select.value)) || isDescendant(item, self)) {
                select.value = "";
            }
        });

        refreshParentSelects();

        items().forEach(function (item) {
            var depth = depthFor(item);
            item.style.setProperty("--kp-menu-depth", String(Math.min(5, depth)));
            item.dataset.kpDepth = String(depth);
        });

        reindex();
    }

    function itemTemplate(data) {
        var index = items().length;
        var id = "menu-item-" + Date.now().toString(36) + "-" + index;

        return `<li class="kp-menu-item" data-kp-menu-item data-kp-item="${id}" draggable="true" style="--kp-menu-depth:0"><details open><summary><span class="kp-menu-item-title"><strong>${escapeHtml(data.label)}</strong><em>${escapeHtml(data.type || "Custom Link")}</em></span><span class="kp-menu-item-actions"><button type="button" data-kp-menu-indent title="Make sub item">&rarr;</button><button type="button" data-kp-menu-outdent title="Move up one level">&larr;</button><span aria-hidden="true">&#9662;</span></span></summary><div class="kp-menu-item-settings">
            <input type="hidden" data-kp-item-id name="items[${index}][id]" value="${id}">
            <input type="hidden" name="items[${index}][type]" value="${escapeHtml(data.type || "Custom Link")}">
            <input type="hidden" name="items[${index}][object_type]" value="${escapeHtml(data.objectType || "")}">
            <input type="hidden" name="items[${index}][object_id]" value="${escapeHtml(data.objectId || "0")}">
            <label>URL <input name="items[${index}][url]" value="${escapeHtml(data.url)}"></label>
            <label>Navigation Label <input data-kp-menu-label name="items[${index}][label]" value="${escapeHtml(data.label)}"></label>
            <div class="kp-menu-item-grid"><label>Menu Parent <select data-kp-parent-select name="items[${index}][parent]"><option value="">No Parent</option></select></label><label>Menu Order <input data-kp-position type="number" name="items[${index}][position]" value="${(index + 1) * 10}"></label></div>
            <div class="kp-menu-item-grid"><label>Open Link In <select name="items[${index}][target]"><option value="">Same tab</option><option value="_blank">New tab</option></select></label><label>CSS Classes <input name="items[${index}][class]" value=""></label></div>
            <p><a href="#" data-kp-remove-menu-item>Remove</a> <span class="kp-muted">|</span> <a href="#" data-kp-cancel-menu-item>Cancel</a></p>
        </div></details></li>`;
    }

    function addItem(data) {
        if (!list) return;
        list.insertAdjacentHTML("beforeend", itemTemplate(data));
        syncStructure();
    }

    function activePanel(box) {
        return box.querySelector(".kp-menu-tab-panel.is-active") || box;
    }

    document.querySelectorAll(".kp-menu-add-box").forEach(function (box) {
        box.querySelectorAll("[data-kp-menu-tab]").forEach(function (tab) {
            tab.addEventListener("click", function () {
                var target = tab.dataset.kpMenuTab;
                box.querySelectorAll("[data-kp-menu-tab]").forEach(function (button) {
                    button.classList.toggle("is-active", button === tab);
                });
                box.querySelectorAll("[data-kp-menu-panel]").forEach(function (panel) {
                    var active = panel.dataset.kpMenuPanel === target;
                    panel.hidden = !active;
                    panel.classList.toggle("is-active", active);
                });
            });
        });

        box.querySelectorAll("[data-kp-menu-search]").forEach(function (input) {
            input.addEventListener("input", function () {
                var query = input.value.trim().toLowerCase();
                input.closest("[data-kp-menu-panel]").querySelectorAll(".kp-menu-choice").forEach(function (choice) {
                    choice.hidden = query !== "" && choice.textContent.toLowerCase().indexOf(query) === -1;
                });
            });
        });
    });

    document.querySelectorAll("[data-kp-add-selected]").forEach(function (button) {
        button.addEventListener("click", function () {
            var box = button.closest(".kp-menu-add-box");
            activePanel(box).querySelectorAll("[data-kp-add-menu-item]:checked").forEach(function (input) {
                addItem({
                    label: input.dataset.label,
                    url: input.dataset.url,
                    type: input.dataset.type,
                    objectType: input.dataset.objectType,
                    objectId: input.dataset.objectId
                });
                input.checked = false;
            });
            var toggle = box.querySelector("[data-kp-panel-select-all]");
            if (toggle) toggle.checked = false;
        });
    });

    document.querySelectorAll("[data-kp-add-custom]").forEach(function (button) {
        button.addEventListener("click", function () {
            var box = button.closest(".kp-menu-add-box");
            var url = box.querySelector("[data-kp-custom-url]").value.trim();
            var label = box.querySelector("[data-kp-custom-label]").value.trim();
            if (!url || !label) return;
            addItem({label: label, url: url, type: "Custom Link"});
            box.querySelector("[data-kp-custom-url]").value = "";
            box.querySelector("[data-kp-custom-label]").value = "";
        });
    });

    document.addEventListener("click", function (event) {
        var indent = event.target.closest("[data-kp-menu-indent]");
        if (indent) {
            event.preventDefault();
            event.stopPropagation();
            indentItem(indent.closest("[data-kp-menu-item]"));
            return;
        }

        var outdent = event.target.closest("[data-kp-menu-outdent]");
        if (outdent) {
            event.preventDefault();
            event.stopPropagation();
            outdentItem(outdent.closest("[data-kp-menu-item]"));
            return;
        }

        var remove = event.target.closest("[data-kp-remove-menu-item]");
        if (remove) {
            event.preventDefault();
            remove.closest("[data-kp-menu-item]").remove();
            syncStructure();
        }
        if (event.target.closest("[data-kp-cancel-menu-item]")) {
            event.preventDefault();
            event.target.closest("details").open = false;
        }
    });

    document.querySelectorAll("[data-kp-panel-select-all]").forEach(function (toggle) {
        toggle.addEventListener("change", function () {
            activePanel(toggle.closest(".kp-menu-add-box")).querySelectorAll("[data-kp-add-menu-item]").forEach(function (input) {
                if (input.closest(".kp-menu-choice").hidden) return;
                input.checked = toggle.checked;
            });
        });
    });

    document.addEventListener("input", function (event) {
        if (!event.target.matches("[data-kp-menu-label]")) return;
        var item = event.target.closest("[data-kp-menu-item]");
        var title = item ? item.querySelector("summary strong") : null;
        if (title) title.textContent = event.target.value || "Menu item";
        syncStructure();
    });

    document.addEventListener("change", function (event) {
        if (event.target.matches("[data-kp-parent-select]")) {
            syncStructure();
        }
    });

    function indentItem(item) {
        if (!item || depthFor(item) >= 5) return;
        var previous = item.previousElementSibling;
        while (previous && !previous.matches("[data-kp-menu-item]")) {
            previous = previous.previousElementSibling;
        }
        var select = parentSelect(item);
        if (previous && select) {
            select.value = itemId(previous);
            syncStructure();
        }
    }

    function outdentItem(item) {
        if (!item) return;
        var select = parentSelect(item);
        var parentItem = select && select.value ? itemById(select.value) : null;

        if (select) {
            select.value = parentItem ? parentValue(parentItem) : "";
            syncStructure();
        }
    }

    if (!list) return;

    var dragged = null;
    var draggedGroup = [];
    var dragStartX = 0;
    var dragLastX = 0;

    function collectDragGroup(item) {
        var group = [item];
        var depth = depthFor(item);
        var current = item.nextElementSibling;

        while (current && current.matches("[data-kp-menu-item]") && depthFor(current) > depth) {
            group.push(current);
            current = current.nextElementSibling;
        }

        return group;
    }

    list.addEventListener("dragstart", function (event) {
        var item = event.target.closest("[data-kp-menu-item]");
        if (!item) return;
        dragged = item;
        draggedGroup = collectDragGroup(item);
        dragStartX = event.clientX;
        dragLastX = event.clientX;
        draggedGroup.forEach(function (groupItem) {
            groupItem.classList.add("is-dragging");
        });
        event.dataTransfer.effectAllowed = "move";
    });

    list.addEventListener("dragover", function (event) {
        event.preventDefault();
        dragLastX = event.clientX;
        var target = event.target.closest("[data-kp-menu-item]");
        if (!dragged || !target || draggedGroup.indexOf(target) !== -1) return;
        var box = target.getBoundingClientRect();
        var after = event.clientY > box.top + box.height / 2;
        var reference = after ? target.nextSibling : target;
        if (reference && draggedGroup.indexOf(reference) !== -1) return;
        draggedGroup.forEach(function (groupItem) {
            list.insertBefore(groupItem, reference);
        });
    });

    list.addEventListener("dragend", function () {
        if (dragged && dragLastX - dragStartX > 42) {
            indentItem(dragged);
        } else if (dragged && dragStartX - dragLastX > 42) {
            outdentItem(dragged);
        }

        draggedGroup.forEach(function (groupItem) {
            groupItem.classList.remove("is-dragging");
        });

        dragged = null;
        draggedGroup = [];
        syncStructure();
    });

    syncStructure();
})();
</script>
HTML;
    }
}
