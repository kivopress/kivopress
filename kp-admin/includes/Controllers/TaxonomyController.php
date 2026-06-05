<?php

declare(strict_types=1);

namespace Kivopress\Admin\Controllers;

use Kivopress\Response;

final class TaxonomyController extends Controller
{
    public function index(string $taxonomy): Response
    {
        $schema = $this->taxonomyOrFail($taxonomy);

        if ($redirect = $this->guardCapability($this->capability($schema))) {
            return $redirect;
        }

        $rows = '';

        foreach ($this->content->terms($schema['name']) as $term) {
            $rows .= '<tr>
                <td><a class="kp-link-strong" href="/admin/taxonomies/' . \e($schema['name']) . '/' . $term['id'] . '/edit">' . \e($term['name']) . '</a></td>
                <td>' . \e($term['slug']) . '</td>
                <td>' . \e((string) $term['count']) . '</td>
                <td class="kp-actions-cell">
                    <div class="kp-row-actions">
                        <a class="kp-row-action" href="/admin/taxonomies/' . \e($schema['name']) . '/' . $term['id'] . '/edit">' . $this->view->icon('edit') . 'Edit</a>
                        <form method="post" action="/admin/taxonomies/' . \e($schema['name']) . '/' . $term['id'] . '/delete" class="kp-inline-form" onsubmit="return confirm(\'Delete this term?\')">
                            ' . $this->view->csrfField() . '
                            <button class="kp-row-action kp-row-action-danger">' . $this->view->icon('delete') . 'Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="kp-empty">No terms yet.</td></tr>';
        }

        $html = '<div class="kp-taxonomy-manager">
            <section class="kp-panel">
                <div class="kp-panel-head"><div><h2>Add ' . \e((string) $schema['singular_label']) . '</h2><p>Create reusable terms for content organization and APIs.</p></div></div>
                ' . $this->termForm('/admin/taxonomies/' . $schema['name'], $schema) . '
            </section>
            <section class="kp-panel">
                <div class="kp-table-wrap"><table><thead><tr><th>Name</th><th>Slug</th><th>Count</th><th class="kp-actions-head">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>
            </section>
        </div>';

        return $this->view->layout((string) $schema['label'], $html);
    }

    public function store(string $taxonomy): Response
    {
        $schema = $this->taxonomyOrFail($taxonomy);

        if ($redirect = $this->guardPostCapability($this->capability($schema))) {
            return $redirect;
        }

        try {
            $this->content->createTerm($schema['name'], $_POST);
            $this->auth->flash('notice', 'Term created.');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/taxonomies/' . $schema['name']);
    }

    public function edit(string $taxonomy, int $id): Response
    {
        $schema = $this->taxonomyOrFail($taxonomy);

        if ($redirect = $this->guardCapability($this->capability($schema))) {
            return $redirect;
        }

        $term = $this->content->term($schema['name'], $id);

        if (!$term) {
            return $this->view->layout('Not Found', '<p>Term not found.</p>', true, 404);
        }

        $html = '<section class="kp-panel">
            <div class="kp-panel-head"><div><h2>Edit ' . \e((string) $schema['singular_label']) . '</h2><p>Update the term name, slug, parent, and description.</p></div></div>
            ' . $this->termForm('/admin/taxonomies/' . $schema['name'] . '/' . $term['id'], $schema, $term) . '
        </section>';

        return $this->view->layout('Edit ' . (string) $schema['singular_label'], $html);
    }

    public function update(string $taxonomy, int $id): Response
    {
        $schema = $this->taxonomyOrFail($taxonomy);

        if ($redirect = $this->guardPostCapability($this->capability($schema))) {
            return $redirect;
        }

        try {
            $this->content->updateTerm($schema['name'], $id, $_POST);
            $this->auth->flash('notice', 'Term updated.');
        } catch (\Throwable $exception) {
            $this->auth->flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/taxonomies/' . $schema['name'] . '/' . $id . '/edit');
    }

    public function delete(string $taxonomy, int $id): Response
    {
        $schema = $this->taxonomyOrFail($taxonomy);

        if ($redirect = $this->guardPostCapability($this->capability($schema))) {
            return $redirect;
        }

        $this->content->deleteTerm($schema['name'], $id);
        $this->auth->flash('notice', 'Term deleted.');

        return Response::redirect('/admin/taxonomies/' . $schema['name']);
    }

    private function termForm(string $action, array $schema, ?array $term = null): string
    {
        $parent = '';

        if ($schema['hierarchical']) {
            $options = '<option value="">None</option>';

            foreach ($this->content->terms($schema['name']) as $candidate) {
                if ($term && $candidate['id'] === $term['id']) {
                    continue;
                }

                $options .= '<option value="' . $candidate['id'] . '" ' . (($term['parent_id'] ?? null) === $candidate['id'] ? 'selected' : '') . '>' . \e($candidate['name']) . '</option>';
            }

            $parent = '<label>Parent<select name="parent_id">' . $options . '</select></label>';
        }

        return $this->view->form($action, '
            <label>Name<input name="name" value="' . \e((string) ($term['name'] ?? '')) . '" required></label>
            <label>Slug<input name="slug" value="' . \e((string) ($term['slug'] ?? '')) . '" placeholder="Auto-generated from name"></label>
            ' . $parent . '
            <label>Description<textarea name="description" rows="4">' . \e((string) ($term['description'] ?? '')) . '</textarea></label>
            <button>' . ($term ? 'Update Term' : 'Add Term') . '</button>
        ');
    }

    private function taxonomyOrFail(string $taxonomy): array
    {
        $schema = $this->content->taxonomy($taxonomy);

        if (!$schema) {
            throw new \InvalidArgumentException('Unknown taxonomy.');
        }

        return $schema;
    }

    private function capability(array $taxonomy): string
    {
        foreach ($taxonomy['content_types'] ?? [] as $typeName) {
            $type = $this->content->type((string) $typeName);

            if ($type) {
                return 'edit_' . $type['api_slug'];
            }
        }

        return 'manage_settings';
    }
}
