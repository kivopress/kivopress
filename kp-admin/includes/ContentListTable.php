<?php

declare(strict_types=1);

namespace Kivopress\Admin;

use Kivopress\App;
use Kivopress\Auth;
use Kivopress\Content;

final class ContentListTable
{
    public function __construct(
        private App $app,
        private Auth $auth,
        private Content $content,
        private AdminView $view
    ) {
    }

    public function render(array $schema): string
    {
        $query = $this->listQuery($schema);
        $items = $this->content->all($schema['name'], $query);
        $total = $this->content->countAll($schema['name'], $query);
        $columns = $this->listColumns($schema);
        $rows = $this->listRows($schema, $items, $columns);
        $colspan = count($columns) + 1;

        if ($rows === '') {
            $rows = '<tr><td colspan="' . $colspan . '" class="kp-empty">No content found.</td></tr>';
        }

        return '<div class="kp-toolbar kp-list-toolbar">
                <a class="kp-button" href="/admin/content/' . \e($schema['name']) . '/new">' . $this->view->icon('add') . 'Add New</a>
                <form method="get" action="/admin/content/' . \e($schema['name']) . '" class="kp-search-form">
                    <input name="s" value="' . \e((string) ($_GET['s'] ?? '')) . '" placeholder="Search ' . \e(strtolower((string) $schema['label'])) . '">
                    <button class="kp-button kp-button-secondary">' . $this->view->icon('search') . 'Search</button>
                </form>
            </div>
            ' . $this->statusTabs($schema) . '
            ' . $this->listFilters($schema) . '
            <form method="post" action="/admin/content/' . \e($schema['name']) . '/bulk" class="kp-list-form">
                ' . $this->view->csrfField() . '
                <section class="kp-panel kp-list-panel">
                    <div class="kp-list-controls">
                        <div class="kp-bulk-actions">
                            <select name="bulk_action"><option value="">Bulk actions</option><option value="delete">Delete</option></select>
                            <button class="kp-button kp-button-secondary">Apply</button>
                        </div>
                    </div>
                    <div class="kp-table-wrap">
                        <table class="kp-list-table">
                            <thead><tr><th class="kp-check-column"><input type="checkbox" data-kp-check-all></th>' . $this->tableHeaders($schema, $columns) . '</tr></thead>
                            <tbody>' . $rows . '</tbody>
                            <tfoot><tr><th class="kp-check-column"></th>' . $this->tableHeaders($schema, $columns) . '</tr></tfoot>
                        </table>
                    </div>
                    ' . $this->pagination($schema, $total, $query) . '
                </section>
            </form>
            ' . $this->checkAllScript();
    }

    private function listQuery(array $schema): array
    {
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['draft', 'published', 'private'], true) ? $status : '';
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $orderby = (string) ($_GET['orderby'] ?? 'updated_at');
        $order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $terms = [];

        foreach ($this->content->taxonomiesFor($schema['name']) as $taxonomy) {
            $value = trim((string) ($_GET[$taxonomy['name']] ?? ''));

            if ($value === '') {
                continue;
            }

            $term = $this->content->term($taxonomy['name'], $value);

            if ($term) {
                $terms[$taxonomy['name']] = $term['id'];
            }
        }

        $query = [
            'status' => $status ?: null,
            'search' => trim((string) ($_GET['s'] ?? '')),
            'include_drafts' => true,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => in_array($orderby, ['title', 'slug', 'status', 'created_at', 'updated_at'], true) ? $orderby : 'updated_at',
            'order' => $order,
            'terms' => $terms,
        ];

        return apply_filters('content.list_query', $query, $schema, $_GET);
    }

    private function statusTabs(array $schema): string
    {
        $active = (string) ($_GET['status'] ?? '');
        $tabs = ['' => 'All', 'published' => 'Published', 'draft' => 'Drafts', 'private' => 'Private'];
        $html = '<nav class="kp-list-tabs" aria-label="Content status">';

        foreach ($tabs as $status => $label) {
            $count = $status === ''
                ? $this->content->countAll($schema['name'], ['include_drafts' => true])
                : $this->content->countAll($schema['name'], ['include_drafts' => true, 'status' => $status]);
            $href = '/admin/content/' . $schema['name'] . ($status === '' ? '' : '?status=' . rawurlencode($status));
            $html .= '<a href="' . \e($href) . '" class="' . ($active === $status ? 'is-active' : '') . '">' . \e($label) . ' <span>' . $count . '</span></a>';
        }

        return $html . '</nav>';
    }

    private function listFilters(array $schema): string
    {
        $taxonomies = array_values(array_filter(
            $this->content->taxonomiesFor($schema['name']),
            fn (array $taxonomy): bool => (bool) ($taxonomy['show_admin_filter'] ?? true)
        ));

        if ($taxonomies === []) {
            return '';
        }

        $filters = '<form method="get" action="/admin/content/' . \e($schema['name']) . '" class="kp-list-controls">
            <div class="kp-filter-actions">';

        foreach ($taxonomies as $taxonomy) {
            $current = (string) ($_GET[$taxonomy['name']] ?? '');
            $filters .= '<select name="' . \e($taxonomy['name']) . '"><option value="">' . \e((string) $taxonomy['label']) . '</option>';

            foreach ($this->content->terms($taxonomy['name']) as $term) {
                $filters .= '<option value="' . $term['id'] . '" ' . ((string) $term['id'] === $current ? 'selected' : '') . '>' . \e($term['name']) . '</option>';
            }

            $filters .= '</select>';
        }

        $filters .= '<input type="hidden" name="status" value="' . \e((string) ($_GET['status'] ?? '')) . '">
                <input type="hidden" name="s" value="' . \e((string) ($_GET['s'] ?? '')) . '">
                <button class="kp-button kp-button-secondary">Filter</button>
            </div>
        </form>';

        return apply_filters('content.list_filters', $filters, $schema, $this->view);
    }

    private function listColumns(array $schema): array
    {
        $columns = [
            'title' => 'Title',
            'author' => 'Author',
            'slug' => 'Slug',
            'status' => 'Status',
        ];

        foreach ($this->content->taxonomiesFor($schema['name']) as $taxonomy) {
            $columns['taxonomy:' . $taxonomy['name']] = $taxonomy['label'];
        }

        $columns['updated_at'] = 'Updated';

        return apply_filters('content.list_columns', $columns, $schema);
    }

    private function tableHeaders(array $schema, array $columns): string
    {
        $sortable = ['title', 'slug', 'status', 'created_at', 'updated_at'];
        $active = (string) ($_GET['orderby'] ?? 'updated_at');
        $order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $html = '';

        foreach ($columns as $key => $label) {
            $class = $key === 'actions' ? ' class="kp-actions-head"' : '';

            if (in_array($key, $sortable, true)) {
                $next = $active === $key && $order === 'asc' ? 'desc' : 'asc';
                $href = $this->listUrl($schema, ['orderby' => $key, 'order' => $next]);
                $label = '<a href="' . \e($href) . '">' . \e((string) $label) . ($active === $key ? ' ' . ($order === 'asc' ? '&uarr;' : '&darr;') : '') . '</a>';
            } else {
                $label = \e((string) $label);
            }

            $html .= '<th' . $class . '>' . $label . '</th>';
        }

        return $html;
    }

    private function listRows(array $schema, array $items, array $columns): string
    {
        $html = '';

        foreach ($items as $item) {
            $html .= '<tr><th class="kp-check-column"><input type="checkbox" name="ids[]" value="' . $item['id'] . '"></th>';

            foreach ($columns as $column => $label) {
                $html .= '<td' . ($column === 'actions' ? ' class="kp-actions-cell"' : '') . '>' . $this->listColumn($schema, $item, (string) $column) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html;
    }

    private function listColumn(array $schema, array $item, string $column): string
    {
        $custom = apply_filters('content.list_column', null, $column, $item, $schema, $this->view);

        if ($custom !== null) {
            return (string) $custom;
        }

        if (str_starts_with($column, 'taxonomy:')) {
            $taxonomy = substr($column, 9);
            $terms = array_map(fn (array $term): string => $term['name'], $item['terms'][$taxonomy] ?? []);

            return $terms ? \e(implode(', ', $terms)) : '<span class="kp-muted">None</span>';
        }

        return match ($column) {
            'title' => $this->titleColumn($schema, $item),
            'author' => \e($this->authorName((int) ($item['author_id'] ?? 0))),
            'slug' => \e($item['slug']),
            'status' => '<span class="kp-pill">' . \e($item['status']) . '</span>',
            'created_at' => \e($item['created_at']),
            'updated_at' => \e($item['updated_at']),
            'actions' => $this->rowActions($schema, $item),
            default => \e($item[$column] ?? ''),
        };
    }

    private function titleColumn(array $schema, array $item): string
    {
        $editUrl = '/admin/content/' . $schema['name'] . '/' . $item['id'] . '/edit';
        $badge = $this->isFrontPageItem($schema, $item) ? '<span class="kp-badge kp-badge-front">Front page</span>' : '';

        return '<div class="kp-title-cell"><a class="kp-link-strong" href="' . \e($editUrl) . '">' . \e($item['title']) . '</a>' . $badge . '</div>
            <div class="kp-row-actions kp-row-actions-under">' . $this->rowActions($schema, $item) . '</div>';
    }

    private function isFrontPageItem(array $schema, array $item): bool
    {
        if (($schema['name'] ?? '') !== 'page') {
            return false;
        }

        $frontPageId = option('front_page_id', null);

        if ($frontPageId !== null) {
            return (int) $frontPageId > 0 && (int) ($item['id'] ?? 0) === (int) $frontPageId;
        }

        return (string) ($item['slug'] ?? '') === 'home';
    }

    private function authorName(int $id): string
    {
        static $authors = [];

        if ($id <= 0) {
            return 'None';
        }

        if (!array_key_exists($id, $authors)) {
            $authors[$id] = $this->auth->userById($id)['name'] ?? 'Unknown';
        }

        return (string) $authors[$id];
    }

    private function rowActions(array $schema, array $item): string
    {
        $editUrl = '/admin/content/' . $schema['name'] . '/' . $item['id'] . '/edit';
        $actions = [
            'edit' => '<a class="kp-row-action" href="' . \e($editUrl) . '">' . $this->view->icon('edit') . 'Edit</a>',
        ];

        if (($schema['public'] ?? true) && $item['status'] === 'published') {
            $actions['view'] = '<a class="kp-row-action" href="' . \e(\content_url($item)) . '" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'View</a>';
        }

        if ($this->auth->can('delete_' . $schema['api_slug'])) {
            $actions['delete'] = '<form method="post" action="/admin/content/' . \e($schema['name']) . '/' . $item['id'] . '/delete" class="kp-inline-form" onsubmit="return confirm(\'Delete this item?\')">
                ' . $this->view->csrfField() . '
                <button class="kp-row-action kp-row-action-danger">' . $this->view->icon('delete') . 'Delete</button>
            </form>';
        }

        $actions = apply_filters('content.row_actions', $actions, $item, $schema, $this->view);

        return '<div class="kp-row-actions">' . implode('', array_filter($actions, 'is_string')) . '</div>';
    }

    private function pagination(array $schema, int $total, array $query): string
    {
        $perPage = max(1, (int) $query['limit']);
        $current = max(1, (int) floor(((int) $query['offset']) / $perPage) + 1);
        $pages = max(1, (int) ceil($total / $perPage));

        return '<div class="kp-list-pagination">
            <span>' . $total . ' items</span>
            <a class="kp-row-action" href="' . \e($this->listUrl($schema, ['paged' => max(1, $current - 1)])) . '">Previous</a>
            <span>' . $current . ' of ' . $pages . '</span>
            <a class="kp-row-action" href="' . \e($this->listUrl($schema, ['paged' => min($pages, $current + 1)])) . '">Next</a>
        </div>';
    }

    private function listUrl(array $schema, array $overrides = []): string
    {
        $query = array_filter(array_merge($_GET, $overrides), fn (mixed $value): bool => $value !== '' && $value !== null);

        return '/admin/content/' . $schema['name'] . ($query ? '?' . http_build_query($query) : '');
    }

    private function checkAllScript(): string
    {
        return '<script>
document.querySelectorAll("[data-kp-check-all]").forEach(function (toggle) {
    toggle.addEventListener("change", function () {
        var table = toggle.closest("table");
        if (!table) return;
        table.querySelectorAll("tbody input[type=checkbox]").forEach(function (box) {
            box.checked = toggle.checked;
        });
    });
});
</script>';
    }
}
