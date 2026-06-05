<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Admin\ContentListTable;
use Kivopress\Response;

final class ContentController extends Controller
{
    public function index(string $type): Response
    {
        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if ($redirect = $this->guardCapability('edit_' . $schema['api_slug'])) {
            return $redirect;
        }

        $html = (new ContentListTable($this->app, $this->auth, $this->content, $this->view))->render($schema);

        return $this->view->layout($schema['label'], $html);
    }

    public function form(string $type, ?int $id = null): Response
    {
        $this->view->queueEditorAssets();
        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if ($redirect = $this->guardCapability('edit_' . $schema['api_slug'])) {
            return $redirect;
        }

        $this->queueSlugAssets();
        $item = $id ? $this->content->find($schema['name'], $id, true) : null;
        $action = $item ? "/admin/content/{$schema['name']}/{$item['id']}" : "/admin/content/{$schema['name']}";
        $title = $item ? 'Edit ' . $schema['singular_label'] : 'New ' . $schema['singular_label'];
        $primaryFields = '';
        $sideFields = '';
        $boxes = [];

        if (in_array('title', $schema['supports'], true)) {
            $primaryFields .= '<label class="kp-field kp-title-label"><span class="kp-field-label">Title</span><input class="kp-title-input" name="title" value="' . \e($item['title'] ?? '') . '" required data-kp-title-source></label>';
        }

        if (in_array('slug', $schema['supports'], true)) {
            $primaryFields .= $this->permalinkField($schema, $item);
        }

        if (in_array('editor', $schema['supports'], true)) {
            $primaryFields .= '<div class="kp-field kp-body-field">' . $this->view->editorInput('body', $item['body'] ?? '', '') . '</div>';
        }

        if (in_array('excerpt', $schema['supports'], true)) {
            $boxes[] = $this->metaBox('excerpt', 'Excerpt', '<textarea name="excerpt" rows="4" aria-label="Excerpt">' . \e($item['excerpt'] ?? '') . '</textarea>', 'normal', 20);
        }

        $status = $item['status'] ?? 'draft';

        $fieldBoxes = [];

        foreach ($schema['fields'] as $name => $field) {
            $boxTitle = (string) ($field['box'] ?? $field['group'] ?? 'Custom Fields');
            $boxId = (string) ($field['box_id'] ?? $this->boxId($boxTitle));
            $context = (string) ($field['context'] ?? $field['box_context'] ?? 'normal');

            $fieldBoxes[$boxId] ??= $this->metaBox($boxId, $boxTitle, '', $context, (int) ($field['box_priority'] ?? $field['priority'] ?? 40), (string) ($field['box_class'] ?? ''));
            $fieldBoxes[$boxId]['html'] .= $this->fieldInput($name, $field, $item['fields'][$name] ?? $field['default'] ?? null);
        }

        $boxes = array_merge($boxes, $this->taxonomyBoxes($schema, $item), array_values($fieldBoxes));
        $boxes = apply_filters('content.meta_boxes', $boxes, $schema, $item, $this->view);
        $primaryFields = apply_filters('content.editor_primary', $primaryFields, $schema, $item, $this->view);
        $extraPublishFields = apply_filters('content.publish_fields', $sideFields, $schema, $item, $this->view);

        $sideFields = $this->publishPanel($schema, $item, $status) . (string) $extraPublishFields;
        $delete = $item ? '<button class="kp-publish-trash" formaction="/admin/content/' . \e($schema['name']) . '/' . $item['id'] . '/delete" onclick="return confirm(\'Move this item to trash?\')">Move to Trash</button>' : '';
        $view = ($item && ($schema['public'] ?? true) && $item['status'] === 'published')
            ? '<a class="kp-button kp-button-secondary" href="' . \e(\content_url($item)) . '" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'View</a>'
            : '';
        $sideFields .= '<div class="kp-publish-actions">' . $delete . '<div>' . $view . '<button>' . $this->view->icon('save') . ($item ? 'Update' : 'Publish') . '</button></div></div>';

        $form = '<form method="post" action="' . \e($action) . '" class="kp-content-editor">
            ' . $this->view->csrfField() . '
            <div class="kp-editor-layout">
                <section class="kp-editor-main">
                    <div class="kp-editor-primary">' . $primaryFields . '</div>
                    ' . $this->renderMetaBoxes($boxes, 'normal') . '
                </section>
                <aside class="kp-editor-side">
                    ' . $this->renderMetaBoxes([$this->metaBox('publish', 'Publish', $sideFields, 'side', 0, 'kp-publish-box')], 'side') . '
                    ' . $this->renderMetaBoxes($boxes, 'side') . '
                </aside>
            </div>
        </form>';

        return $this->view->layout($title, $form);
    }

    public function store(string $type): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if (!$this->auth->can('edit_' . $schema['api_slug'])) {
            return $this->forbidden();
        }

        $payload = $this->payload($schema);

        if ($redirect = $this->validatePayload($schema, $payload, null)) {
            return $redirect;
        }

        $item = $this->content->create($schema['name'], $payload, $this->auth->user()['id']);
        $this->auth->flash('notice', 'Content created.');

        return Response::redirect('/admin/content/' . $schema['name'] . '/' . $item['id'] . '/edit');
    }

    public function update(string $type, int $id): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if (!$this->auth->can('edit_' . $schema['api_slug'])) {
            return $this->forbidden();
        }

        $payload = $this->payload($schema);

        if ($redirect = $this->validatePayload($schema, $payload, $id)) {
            return $redirect;
        }

        $this->content->update($schema['name'], $id, $payload);
        $this->auth->flash('notice', 'Content saved.');

        return Response::redirect('/admin/content/' . $schema['name'] . '/' . $id . '/edit');
    }

    public function delete(string $type, int $id): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if (!$this->auth->can('delete_' . $schema['api_slug'])) {
            return $this->forbidden();
        }

        $this->content->delete($schema['name'], $id);
        $this->auth->flash('notice', 'Content deleted.');

        return Response::redirect('/admin/content/' . $schema['name']);
    }

    public function bulk(string $type): Response
    {
        if ($redirect = $this->guardPost()) {
            return $redirect;
        }

        $schema = $this->content->type($type);

        if (!$schema) {
            return $this->view->layout('Not Found', '<p>Unknown content type.</p>', true, 404);
        }

        if (!$this->auth->can('delete_' . $schema['api_slug'])) {
            return $this->forbidden();
        }

        $action = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));

        if ($action !== 'delete' || $ids === []) {
            $this->auth->flash('error', 'Choose content and a bulk action.');

            return Response::redirect('/admin/content/' . $schema['name']);
        }

        foreach (array_filter($ids) as $id) {
            $this->content->delete($schema['name'], $id);
        }

        $this->auth->flash('notice', 'Selected content deleted.');

        return Response::redirect('/admin/content/' . $schema['name']);
    }

    private function metaBox(string $id, string $title, string $html, string $context = 'normal', int $priority = 10, string $class = ''): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'html' => $html,
            'context' => $context === 'side' ? 'side' : 'normal',
            'priority' => $priority,
            'class' => $class,
        ];
    }

    private function permalinkField(array $schema, ?array $item): string
    {
        $slug = (string) ($item['slug'] ?? '');
        $preview = $item ? $this->baseUrl() . \content_url($item) : '#';
        $structure = $schema['name'] === 'post' ? (string) option('permalink_structure', '/%postname%/') : '/%postname%/';

        return '<div class="kp-permalink-row" data-kp-permalink data-kp-base-url="' . \e($this->baseUrl()) . '" data-kp-structure="' . \e($structure) . '">
            <span>Permalink:</span>
            <a href="' . \e($preview) . '" target="_blank" rel="noopener" data-kp-permalink-preview>' . \e($item ? $preview : 'Slug will be generated from the title') . '</a>
            <button type="button" class="kp-button kp-button-secondary" data-kp-permalink-edit>' . $this->view->icon('edit') . 'Edit</button>
            <input name="slug" value="' . \e($slug) . '" data-kp-slug-target data-kp-pristine="' . ($item ? '0' : '1') . '" hidden>
        </div>';
    }

    private function publishPanel(array $schema, ?array $item, string $status): string
    {
        $statuses = $this->auth->can('publish_' . $schema['api_slug']) ? ['draft', 'published', 'private'] : ['draft', 'private'];
        $published = $item['published_at'] ?? null;
        $visibility = $status === 'private' ? 'Private' : 'Public';
        $summary = [
            ['icon' => 'link', 'label' => 'Status', 'value' => '<select name="status">' . $this->view->optionTags($statuses, $status) . '</select>'],
            ['icon' => 'visibility', 'label' => 'Visibility', 'value' => \e($visibility)],
            ['icon' => 'calendar', 'label' => $published ? 'Published on' : 'Publish', 'value' => \e($published ?: 'Immediately')],
        ];
        $summary = apply_filters('content.publish_summary', $summary, $schema, $item, $this->view);
        $html = '';

        if ($item && ($schema['public'] ?? true)) {
            $html .= '<a class="kp-button kp-button-secondary kp-preview-button" href="' . \e(\content_url($item)) . '" target="_blank" rel="noopener">' . $this->view->icon('open_in_new') . 'Preview Changes</a>';
        }

        $html .= '<div class="kp-publish-summary">';

        foreach ($summary as $row) {
            $html .= '<div class="kp-publish-row">' . $this->view->icon((string) ($row['icon'] ?? 'radio_button_unchecked')) . '<span>' . \e((string) ($row['label'] ?? '')) . '</span><strong>' . (string) ($row['value'] ?? '') . '</strong></div>';
        }

        return $html . '</div>';
    }

    private function validatePayload(array $schema, array $payload, ?int $id): ?Response
    {
        $errors = apply_filters('content.validate_payload', [], $schema, $payload, $id);

        if (!is_array($errors) || $errors === []) {
            return null;
        }

        $this->auth->flash('error', implode(' ', array_map('strval', $errors)));
        $target = $id ? '/admin/content/' . $schema['name'] . '/' . $id . '/edit' : '/admin/content/' . $schema['name'] . '/new';

        return Response::redirect($target);
    }

    private function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function renderMetaBoxes(array $boxes, string $context): string
    {
        $boxes = array_filter($boxes, fn (array $box): bool => ($box['context'] ?? 'normal') === $context && trim((string) ($box['html'] ?? '')) !== '');
        usort($boxes, fn (array $a, array $b): int => ((int) ($a['priority'] ?? 10)) <=> ((int) ($b['priority'] ?? 10)));
        $html = '';

        foreach ($boxes as $box) {
            $classes = trim('kp-meta-box ' . (string) ($box['class'] ?? ''));
            $id = (string) ($box['id'] ?? 'custom');
            $title = (string) ($box['title'] ?? 'Meta Box');
            $bodyId = 'kp-box-body-' . $this->boxId($context . '-' . $id);
            $collapsed = !empty($box['collapsed']);
            $html .= '<section class="' . \e($classes) . '" id="kp-box-' . \e($this->boxId($id)) . '" data-kp-meta-box="' . \e($context . ':' . $id) . '" data-kp-collapsed="' . ($collapsed ? '1' : '0') . '">
                <header class="kp-meta-box-head">
                    <h2>' . \e($title) . '</h2>
                    <button type="button" class="kp-meta-box-toggle" aria-expanded="' . ($collapsed ? 'false' : 'true') . '" aria-controls="' . \e($bodyId) . '" aria-label="Toggle ' . \e($title) . '">' . $this->view->icon('expand_more') . '</button>
                </header>
                <div class="kp-meta-box-body" id="' . \e($bodyId) . '" ' . ($collapsed ? 'hidden' : '') . '>' . (string) $box['html'] . '</div>
            </section>';
        }

        return $html;
    }

    private function boxId(string $title): string
    {
        $id = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', trim($title)));

        return trim($id, '-') ?: 'custom-fields';
    }

    private function payload(array $schema): array
    {
        $fields = [];

        foreach ($schema['fields'] as $name => $field) {
            if (($field['type'] ?? 'text') === 'boolean') {
                $fields[$name] = isset($_POST['fields'][$name]);
                continue;
            }

            if (($field['type'] ?? 'text') === 'media') {
                $mediaId = (int) ($_POST['fields'][$name] ?? 0);
                $fields[$name] = $mediaId > 0 ? $mediaId : null;
                continue;
            }

            $fields[$name] = $_POST['fields'][$name] ?? null;
        }

        return [
            'title' => $_POST['title'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'body' => $_POST['body'] ?? '',
            'excerpt' => $_POST['excerpt'] ?? '',
            'status' => $this->statusFor($schema),
            'fields' => $fields,
            'terms' => is_array($_POST['terms'] ?? null) ? $_POST['terms'] : [],
            'term_names' => is_array($_POST['term_names'] ?? null) ? $_POST['term_names'] : [],
        ];
    }

    private function taxonomyBoxes(array $schema, ?array $item): array
    {
        $boxes = [];

        foreach ($this->content->taxonomiesFor($schema['name']) as $taxonomy) {
            if (!($taxonomy['show_in_editor'] ?? true)) {
                continue;
            }

            $terms = $this->content->terms($taxonomy['name']);
            $current = array_column($item['terms'][$taxonomy['name']] ?? [], 'id');
            $html = ($taxonomy['hierarchical'] ?? false)
                ? $this->hierarchicalTaxonomyBox($taxonomy, $terms, $current)
                : $this->flatTaxonomyBox($taxonomy, $item['terms'][$taxonomy['name']] ?? []);

            $boxes[] = $this->metaBox(
                $taxonomy['name'],
                (string) $taxonomy['label'],
                $html,
                'side',
                (int) ($taxonomy['hierarchical'] ? 10 : 20),
                'kp-taxonomy-box'
            );
        }

        return $boxes;
    }

    private function hierarchicalTaxonomyBox(array $taxonomy, array $terms, array $current): string
    {
        $name = \e($taxonomy['name']);
        $html = '<div class="kp-taxonomy-checklist">';

        foreach ($terms as $term) {
            $html .= '<label class="kp-check"><input type="checkbox" name="terms[' . $name . '][]" value="' . $term['id'] . '" ' . (in_array($term['id'], $current, true) ? 'checked' : '') . '> ' . \e($term['name']) . '</label>';
        }

        if ($terms === []) {
            $html .= '<p class="kp-empty">No ' . \e(strtolower((string) $taxonomy['label'])) . ' yet.</p>';
        }

        return $html . '</div>
            <label class="kp-field kp-taxonomy-add"><span class="kp-field-label">Add ' . \e((string) $taxonomy['singular_label']) . '</span><input name="term_names[' . $name . ']" placeholder="New ' . \e((string) $taxonomy['singular_label']) . '"></label>
            <a class="kp-field-help" href="/admin/taxonomies/' . $name . '">Manage ' . \e((string) $taxonomy['label']) . '</a>';
    }

    private function flatTaxonomyBox(array $taxonomy, array $current): string
    {
        $names = implode(', ', array_map(fn (array $term): string => $term['name'], $current));
        $name = \e($taxonomy['name']);

        return '<label class="kp-field"><span class="kp-field-label">Add ' . \e((string) $taxonomy['label']) . '</span><input name="term_names[' . $name . ']" value="' . \e($names) . '" placeholder="Separate with commas"></label>
            <p class="kp-field-description">Separate ' . \e(strtolower((string) $taxonomy['label'])) . ' with commas.</p>
            <a class="kp-field-help" href="/admin/taxonomies/' . $name . '">Choose from existing ' . \e(strtolower((string) $taxonomy['label'])) . '</a>';
    }

    private function statusFor(array $schema): string
    {
        $status = (string) ($_POST['status'] ?? 'draft');

        if ($status === 'published' && !$this->auth->can('publish_' . $schema['api_slug'])) {
            return 'draft';
        }

        return in_array($status, ['draft', 'published', 'private'], true) ? $status : 'draft';
    }

    private function fieldInput(string $name, array $field, mixed $value): string
    {
        $label = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name)));
        $fieldName = 'fields[' . \e($name) . ']';
        $type = (string) ($field['type'] ?? 'text');
        $value = $type === 'json' ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $value;
        $required = !empty($field['required']) ? ' required' : '';
        $class = trim('kp-field kp-field-' . preg_replace('/[^a-z0-9_-]/i', '', $type) . ' ' . (string) ($field['class'] ?? ''));
        $description = trim((string) ($field['description'] ?? $field['help'] ?? ''));
        $help = $description !== '' ? '<p class="kp-field-description">' . \e($description) . '</p>' : '';
        $custom = apply_filters('content.field_input', '', $name, $field, $value, $fieldName);

        if (is_string($custom) && trim($custom) !== '') {
            return $custom;
        }

        return match ($type) {
            'select' => $this->selectField($fieldName, $label, $field, $value, $class, $help, $required),
            'richtext' => '<div class="' . \e($class) . '">' . $this->view->editorInput($fieldName, (string) $value, $label) . $help . '</div>',
            'textarea', 'json' => '<label class="' . \e($class) . '"><span class="kp-field-label">' . \e($label) . '</span><textarea name="' . $fieldName . '" rows="5"' . $required . '>' . \e((string) $value) . '</textarea>' . $help . '</label>',
            'number' => '<label class="' . \e($class) . '"><span class="kp-field-label">' . \e($label) . '</span><input type="number" step="any" name="' . $fieldName . '" value="' . \e((string) $value) . '"' . $required . '>' . $help . '</label>',
            'boolean' => '<div class="' . \e($class) . '"><label class="kp-check"><input type="checkbox" name="' . $fieldName . '" value="1" ' . ($value ? 'checked' : '') . $required . '> ' . \e($label) . '</label>' . $help . '</div>',
            'date' => '<label class="' . \e($class) . '"><span class="kp-field-label">' . \e($label) . '</span><input type="date" name="' . $fieldName . '" value="' . \e((string) $value) . '"' . $required . '>' . $help . '</label>',
            'media' => $this->mediaField($fieldName, $label, $value, $class, $help, $required),
            default => '<label class="' . \e($class) . '"><span class="kp-field-label">' . \e($label) . '</span><input name="' . $fieldName . '" value="' . \e((string) $value) . '"' . $required . '>' . $help . '</label>',
        };
    }

    private function selectField(string $fieldName, string $label, array $field, mixed $value, string $class, string $help, string $required): string
    {
        $options = $field['options'] ?? [];

        if (($field['options_source'] ?? '') === 'theme.page_templates') {
            $options = $this->app->theme()->pageTemplates();
        } elseif (is_callable($options)) {
            $options = $options();
        }

        $html = '<label class="' . \e($class) . '"><span class="kp-field-label">' . \e($label) . '</span><select name="' . $fieldName . '"' . $required . '>';

        foreach ((array) $options as $key => $optionLabel) {
            if (is_array($optionLabel)) {
                $key = $optionLabel['value'] ?? $key;
                $optionLabel = $optionLabel['label'] ?? $key;
            }

            $html .= '<option value="' . \e((string) $key) . '" ' . ((string) $key === (string) $value ? 'selected' : '') . '>' . \e((string) $optionLabel) . '</option>';
        }

        return $html . '</select>' . $help . '</label>';
    }

    private function mediaField(string $fieldName, string $label, mixed $value, string $class, string $help, string $required): string
    {
        $selected = is_array($value) ? (int) ($value['id'] ?? 0) : (int) $value;
        $item = $selected > 0 ? $this->app->media()->find($selected) : null;
        $inputId = 'kp-media-field-' . preg_replace('/[^a-z0-9_-]+/i', '-', $fieldName);
        $preview = $item
            ? ($item['is_image'] ? '<img src="' . \e($item['url']) . '" alt="' . \e($item['alt'] ?: $item['title']) . '">' : $this->view->icon('perm_media'))
            : '<span>No image selected</span>';

        return '<div class="' . \e($class) . ' kp-media-picker-field">
            <span class="kp-field-label">' . \e($label) . '</span>
            <input id="' . \e($inputId) . '" type="hidden" name="' . $fieldName . '" value="' . ($selected ?: '') . '"' . $required . ' data-kp-media-input>
            <div class="kp-media-picker-preview" data-kp-media-preview>' . $preview . '</div>
            <div class="kp-media-picker-actions">
                <button type="button" class="kp-button kp-button-secondary" data-kp-media-open data-kp-media-input="#' . \e($inputId) . '">' . $this->view->icon('perm_media') . 'Select Media</button>
                <button type="button" class="kp-row-action" data-kp-media-clear data-kp-media-input="#' . \e($inputId) . '">Remove</button>
            </div>
            ' . $help . '<p class="kp-field-help"><a href="/admin/media">Manage media</a> to upload, edit alt text, or copy asset URLs.</p>
        </div>';
    }

    private function queueSlugAssets(): void
    {
        add_filter('admin.footer', fn (string $footer): string => $footer . '
<script>
function kpSlugify(value) {
    return String(value || "")
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, "");
}

document.querySelectorAll("[data-kp-title-source]").forEach(function (title) {
    var form = title.closest("form");
    var slug = form ? form.querySelector("[data-kp-slug-target]") : null;
    var permalink = form ? form.querySelector("[data-kp-permalink]") : null;
    var preview = permalink ? permalink.querySelector("[data-kp-permalink-preview]") : null;
    var edit = permalink ? permalink.querySelector("[data-kp-permalink-edit]") : null;
    if (!slug) return;

    var pathFromSlug = function (value) {
        var clean = kpSlugify(value || title.value || "item");
        var structure = permalink ? permalink.dataset.kpStructure || "/%postname%/" : "/%postname%/";
        var now = new Date();
        return "/" + structure
            .replace(/%year%/g, String(now.getFullYear()))
            .replace(/%monthnum%/g, String(now.getMonth() + 1).padStart(2, "0"))
            .replace(/%day%/g, String(now.getDate()).padStart(2, "0"))
            .replace(/%postname%/g, clean)
            .replace(/^\\/+|\\/+$/g, "") + "/";
    };

    var updatePreview = function () {
        if (!preview) return;
        var path = pathFromSlug(slug.value || title.value);
        var url = (permalink ? permalink.dataset.kpBaseUrl || "" : "") + path;
        preview.textContent = url;
        if (path !== "/item/") preview.setAttribute("href", url);
    };

    var sync = function () {
        if (slug.dataset.kpPristine === "1" || slug.value.trim() === "") {
            slug.value = kpSlugify(title.value);
            slug.dataset.kpPristine = "1";
        }
        updatePreview();
    };

    title.addEventListener("input", sync);
    slug.addEventListener("input", function () {
        slug.dataset.kpPristine = slug.value.trim() === "" ? "1" : "0";
        updatePreview();
    });
    if (edit) edit.addEventListener("click", function () {
        slug.hidden = !slug.hidden;
        if (!slug.hidden) slug.focus();
    });
    sync();
});
</script>');
    }
}
