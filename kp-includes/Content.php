<?php

declare(strict_types=1);

namespace Kivopress;

final class Content
{
    private array $types = [];
    private array $taxonomies = [];

    public function __construct(private Database $db)
    {
    }

    public function registerType(string $name, array $config = []): void
    {
        $name = $this->key($name);
        $label = $config['label'] ?? ucfirst(str_replace('_', ' ', $name));

        $this->types[$name] = array_replace_recursive([
            'name' => $name,
            'label' => $label,
            'singular_label' => rtrim($label, 's'),
            'public' => true,
            'api' => true,
            'api_slug' => $this->plural($name),
            'show_admin' => true,
            'supports' => ['title', 'editor', 'slug', 'status'],
            'fields' => [],
        ], $config);
    }

    public function registerFields(string $contentType, array $fields): void
    {
        $contentType = $this->key($contentType);

        if (!$this->hasType($contentType)) {
            $this->registerType($contentType);
        }

        foreach ($fields as $name => $field) {
            $field = is_array($field) ? $field : ['type' => $field];
            $name = $this->key((string) $name);

            $this->types[$contentType]['fields'][$name] = array_merge([
                'name' => $name,
                'type' => 'text',
                'label' => ucfirst(str_replace('_', ' ', $name)),
                'required' => false,
                'default' => null,
            ], $field);
        }
    }

    public function registerTaxonomy(string $name, array $config): void
    {
        $name = $this->key($name);
        $label = $config['label'] ?? ucfirst(str_replace('_', ' ', $name));

        $this->taxonomies[$name] = array_replace([
            'name' => $name,
            'label' => $label,
            'singular_label' => rtrim($label, 's'),
            'content_types' => [],
            'hierarchical' => false,
            'api' => true,
            'show_admin' => true,
            'show_in_editor' => true,
        ], $config);

        $this->syncTaxonomy($name, [
            'label' => $this->taxonomies[$name]['label'],
            'content_types' => json_encode($this->taxonomies[$name]['content_types'], JSON_UNESCAPED_SLASHES),
            'hierarchical' => $this->taxonomies[$name]['hierarchical'] ? 1 : 0,
            'config' => json_encode($this->taxonomies[$name], JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function taxonomies(): array
    {
        return $this->taxonomies;
    }

    private function syncTaxonomy(string $name, array $row): void
    {
        $existing = $this->db->first('SELECT * FROM taxonomies WHERE name = :name', ['name' => $name]);

        if ($existing) {
            foreach ($row as $column => $value) {
                if ((string) ($existing[$column] ?? '') !== (string) $value) {
                    $this->db->update('taxonomies', $row, 'name = :name', ['name' => $name]);

                    return;
                }
            }

            return;
        }

        try {
            $this->db->insert('taxonomies', ['name' => $name] + $row);
        } catch (\Throwable) {
            $this->db->update('taxonomies', $row, 'name = :name', ['name' => $name]);
        }
    }

    public function taxonomy(string $name): ?array
    {
        return $this->taxonomies[$this->key($name)] ?? null;
    }

    public function taxonomiesFor(string $type): array
    {
        $type = $this->key($type);

        return array_values(array_filter($this->taxonomies, fn (array $taxonomy): bool => in_array($type, $taxonomy['content_types'] ?? [], true)));
    }

    public function types(): array
    {
        return $this->types;
    }

    public function apiTypes(): array
    {
        return array_values(array_filter($this->types, fn (array $type): bool => (bool) $type['api']));
    }

    public function type(string $name): ?array
    {
        return $this->types[$this->key($name)] ?? null;
    }

    public function typeFromApiSlug(string $slug): ?array
    {
        foreach ($this->types as $type) {
            if ($type['api_slug'] === $slug && $type['api']) {
                return $type;
            }
        }

        return null;
    }

    public function hasType(string $name): bool
    {
        return isset($this->types[$this->key($name)]);
    }

    public function all(string $type, array $query = []): array
    {
        $schema = $this->requireType($type);
        $params = ['type' => $schema['name']];
        $where = ['type = :type'];

        if (isset($query['status']) && $query['status'] !== '') {
            $where[] = 'status = :status';
            $params['status'] = $query['status'];
        } elseif (!($query['include_drafts'] ?? false)) {
            $where[] = "status = 'published'";
        }

        if (isset($query['search']) && trim((string) $query['search']) !== '') {
            $where[] = '(title LIKE :search OR body LIKE :search)';
            $params['search'] = '%' . trim((string) $query['search']) . '%';
        }

        foreach ($this->termFilters($query) as $taxonomy => $termId) {
            $key = 'term_' . $taxonomy;
            $where[] = "id IN (SELECT content_id FROM term_relationships WHERE term_id = :{$key})";
            $params[$key] = $termId;
        }

        $limit = max(1, min(100, (int) ($query['limit'] ?? 25)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $order = ($query['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $requestedOrderby = (string) ($query['orderby'] ?? 'created_at');
        $orderby = in_array($requestedOrderby, ['title', 'slug', 'status', 'created_at', 'updated_at'], true)
            ? $requestedOrderby
            : 'created_at';

        $rows = $this->db->select(
            'SELECT * FROM content WHERE ' . implode(' AND ', $where) . " ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $items = $this->hydrateMany($rows);

        if ($this->db->fileMode() && ($terms = $this->termFilters($query))) {
            $items = array_filter($items, fn (array $item): bool => $this->matchesTermFilters($item, $terms));
        }

        return array_values($items);
    }

    public function find(string $type, string|int $idOrSlug, bool $includeDrafts = false): ?array
    {
        $schema = $this->requireType($type);
        $params = ['type' => $schema['name']];
        $column = ctype_digit((string) $idOrSlug) ? 'id' : 'slug';
        $params[$column] = $idOrSlug;
        $draftClause = $includeDrafts ? '' : " AND status = 'published'";

        $row = $this->db->first(
            "SELECT * FROM content WHERE type = :type AND {$column} = :{$column}{$draftClause} LIMIT 1",
            $params
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $type, array $data, ?int $authorId = null): array
    {
        $schema = $this->requireType($type);
        $data = apply_filters('content.create_payload', $data, $schema, $authorId);
        do_action('content.creating', $schema, $data, $authorId);
        do_action('content.' . $schema['name'] . '.creating', $schema, $data, $authorId);

        $now = $this->db->now();
        $status = $this->status($data['status'] ?? 'draft');
        $title = trim((string) ($data['title'] ?? 'Untitled'));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = $this->uniqueSlug($schema['name'], $this->slug($rawSlug !== '' ? $rawSlug : $title));

        $id = $this->db->insert('content', [
            'type' => $schema['name'],
            'status' => $status,
            'slug' => $slug,
            'title' => $title,
            'body' => (string) ($data['body'] ?? $data['content'] ?? ''),
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'author_id' => $authorId,
            'published_at' => $status === 'published' ? ($data['published_at'] ?? $now) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->saveFields($id, $schema['name'], $data);
        $this->saveTerms($id, $schema['name'], $data);

        $content = $this->find($schema['name'], $id, true);
        do_action('content.created', $content, $schema);
        do_action('content.' . $schema['name'] . '.created', $content, $schema);
        do_action('content.saved', $content, $schema, 'created', null);
        do_action('content.' . $schema['name'] . '.saved', $content, $schema, 'created', null);

        return $content;
    }

    public function createPage(array $data, ?int $authorId = null): array
    {
        $payload = $this->pagePayload($data);
        $this->validatePageTemplate($payload);
        $page = $this->create('page', $payload, $authorId);

        if (!empty($data['front_page'])) {
            $this->db->setOption('front_page_id', (int) $page['id']);
        }

        return $page;
    }

    public function ensurePage(array $data, ?int $authorId = null, array $options = []): array
    {
        $payload = $this->pagePayload($data);
        $slug = $this->slug((string) ($payload['slug'] ?: $payload['title']));
        $payload['slug'] = $slug;
        $this->validatePageTemplate($payload);

        $existing = $this->find('page', $slug, true);

        if (!$existing) {
            return $this->createPage($payload + ['front_page' => !empty($data['front_page'])], $authorId);
        }

        $update = [];
        $template = (string) (($payload['fields'] ?? [])['page_template'] ?? '');
        $overwrite = !empty($options['update_existing']) || !empty($data['update_existing']);

        if ($overwrite) {
            $update = $payload;
        } elseif ($template !== '' && (string) (($existing['fields'] ?? [])['page_template'] ?? '') === '') {
            $update['fields']['page_template'] = $template;
        }

        if ($update !== []) {
            $existing = $this->update('page', (int) $existing['id'], $update) ?? $existing;
        }

        if (!empty($options['front_page']) || !empty($data['front_page'])) {
            $this->db->setOption('front_page_id', (int) $existing['id']);
        }

        return $existing;
    }

    public function update(string $type, int $id, array $data): ?array
    {
        $schema = $this->requireType($type);
        $current = $this->find($schema['name'], $id, true);

        if (!$current) {
            return null;
        }

        $data = apply_filters('content.update_payload', $data, $schema, $current);
        do_action('content.updating', $current, $schema, $data);
        do_action('content.' . $schema['name'] . '.updating', $current, $schema, $data);

        $status = array_key_exists('status', $data) ? $this->status($data['status']) : $current['status'];
        $title = array_key_exists('title', $data) ? trim((string) $data['title']) : $current['title'];
        $slug = $current['slug'];

        if (array_key_exists('slug', $data)) {
            $rawSlug = trim((string) $data['slug']);
            $slug = $this->uniqueSlug($schema['name'], $this->slug($rawSlug !== '' ? $rawSlug : $title), $id);
        }
        $publishedAt = $current['published_at'];

        if ($status === 'published' && !$publishedAt) {
            $publishedAt = $this->db->now();
        }

        $this->db->update('content', [
            'status' => $status,
            'slug' => $slug,
            'title' => $title,
            'body' => (string) ($data['body'] ?? $data['content'] ?? $current['body']),
            'excerpt' => (string) ($data['excerpt'] ?? $current['excerpt']),
            'published_at' => $publishedAt,
            'updated_at' => $this->db->now(),
        ], 'id = :id AND type = :type', [
            'id' => $id,
            'type' => $schema['name'],
        ]);

        $this->saveFields($id, $schema['name'], $data);
        $this->saveTerms($id, $schema['name'], $data);

        $content = $this->find($schema['name'], $id, true);
        do_action('content.updated', $content, $current, $schema);
        do_action('content.' . $schema['name'] . '.updated', $content, $current, $schema);

        if ($current['status'] !== $content['status']) {
            do_action('content.status_changed', $content, $current['status'], $content['status'], $current, $schema);
            do_action('content.' . $schema['name'] . '.status_changed', $content, $current['status'], $content['status'], $current, $schema);

            if ($content['status'] === 'published') {
                do_action('content.published', $content, $current, $schema);
                do_action('content.' . $schema['name'] . '.published', $content, $current, $schema);
            }
        }

        do_action('content.saved', $content, $schema, 'updated', $current);
        do_action('content.' . $schema['name'] . '.saved', $content, $schema, 'updated', $current);

        return $content;
    }

    public function delete(string $type, int $id): bool
    {
        $schema = $this->requireType($type);
        $current = $this->find($schema['name'], $id, true);

        if (!$current) {
            return false;
        }

        do_action('content.deleting', $current, $schema);
        do_action('content.' . $schema['name'] . '.deleting', $current, $schema);

        $this->db->execute('DELETE FROM content_meta WHERE content_id = :id', ['id' => $id]);
        $this->db->execute('DELETE FROM term_relationships WHERE content_id = :id', ['id' => $id]);
        $this->db->execute('DELETE FROM content WHERE id = :id AND type = :type', ['id' => $id, 'type' => $schema['name']]);
        do_action('content.deleted', $current, $schema);
        do_action('content.' . $schema['name'] . '.deleted', $current, $schema);

        return true;
    }

    public function count(string $type): int
    {
        $schema = $this->requireType($type);
        $row = $this->db->first('SELECT COUNT(*) AS total FROM content WHERE type = :type', ['type' => $schema['name']]);

        return (int) ($row['total'] ?? 0);
    }

    public function countAll(string $type, array $query = []): int
    {
        $schema = $this->requireType($type);
        $params = ['type' => $schema['name']];
        $where = ['type = :type'];

        if (isset($query['status']) && $query['status'] !== '') {
            $where[] = 'status = :status';
            $params['status'] = $query['status'];
        } elseif (!($query['include_drafts'] ?? false)) {
            $where[] = "status = 'published'";
        }

        if (isset($query['search']) && trim((string) $query['search']) !== '') {
            $where[] = '(title LIKE :search OR body LIKE :search)';
            $params['search'] = '%' . trim((string) $query['search']) . '%';
        }

        foreach ($this->termFilters($query) as $taxonomy => $termId) {
            $key = 'term_' . $taxonomy;
            $where[] = "id IN (SELECT content_id FROM term_relationships WHERE term_id = :{$key})";
            $params[$key] = $termId;
        }

        if ($this->db->fileMode() && ($terms = $this->termFilters($query))) {
            return count($this->all($type, array_merge($query, ['limit' => 100, 'offset' => 0])));
        }

        $row = $this->db->first('SELECT COUNT(*) AS total FROM content WHERE ' . implode(' AND ', $where), $params);

        return (int) ($row['total'] ?? 0);
    }

    public function fieldsFor(string $type): array
    {
        return $this->requireType($type)['fields'];
    }

    public function terms(string $taxonomy): array
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $terms = array_map(
            fn (array $row): array => $this->castTerm($row),
            $this->db->select('SELECT * FROM terms WHERE taxonomy = :taxonomy ORDER BY name ASC', ['taxonomy' => $taxonomy])
        );
        $counts = $this->termCounts(array_column($terms, 'id'));

        foreach ($terms as &$term) {
            $term['count'] = $counts[$term['id']] ?? 0;
        }

        return $terms;
    }

    public function term(string $taxonomy, string|int $idOrSlug): ?array
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $column = ctype_digit((string) $idOrSlug) ? 'id' : 'slug';
        $row = $this->db->first(
            "SELECT * FROM terms WHERE taxonomy = :taxonomy AND {$column} = :value LIMIT 1",
            ['taxonomy' => $taxonomy, 'value' => $idOrSlug]
        );

        return $row ? $this->castTerm($row) : null;
    }

    public function createTerm(string $taxonomy, array $data): array
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('Term name is required.');
        }

        $id = $this->db->insert('terms', [
            'taxonomy' => $taxonomy,
            'slug' => $this->uniqueTermSlug($taxonomy, $this->slug($data['slug'] ?? $name)),
            'name' => $name,
            'parent_id' => $this->parentId($taxonomy, $data['parent_id'] ?? null),
            'description' => trim((string) ($data['description'] ?? '')),
        ]);

        $term = $this->term($taxonomy, $id);
        do_action('taxonomy.term_created', $term, $taxonomy);
        do_action('taxonomy.' . $taxonomy . '.term_created', $term);

        return $term;
    }

    public function updateTerm(string $taxonomy, int $id, array $data): ?array
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $current = $this->term($taxonomy, $id);

        if (!$current) {
            return null;
        }

        $name = trim((string) ($data['name'] ?? $current['name']));

        if ($name === '') {
            throw new \RuntimeException('Term name is required.');
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        $this->db->update('terms', [
            'name' => $name,
            'slug' => $this->uniqueTermSlug($taxonomy, $this->slug($slug !== '' ? $slug : $name), $id),
            'parent_id' => $this->parentId($taxonomy, $data['parent_id'] ?? null, $id),
            'description' => trim((string) ($data['description'] ?? '')),
        ], 'id = :id AND taxonomy = :taxonomy', ['id' => $id, 'taxonomy' => $taxonomy]);

        $term = $this->term($taxonomy, $id);
        do_action('taxonomy.term_updated', $term, $current, $taxonomy);
        do_action('taxonomy.' . $taxonomy . '.term_updated', $term, $current);

        return $term;
    }

    public function deleteTerm(string $taxonomy, int $id): bool
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $term = $this->term($taxonomy, $id);

        if (!$term) {
            return false;
        }

        do_action('taxonomy.term_deleting', $term, $taxonomy);
        do_action('taxonomy.' . $taxonomy . '.term_deleting', $term);

        $this->db->execute('DELETE FROM term_relationships WHERE term_id = :id', ['id' => $id]);
        $this->db->update('terms', ['parent_id' => null], 'parent_id = :id AND taxonomy = :taxonomy', ['id' => $id, 'taxonomy' => $taxonomy]);

        $deleted = $this->db->execute('DELETE FROM terms WHERE id = :id AND taxonomy = :taxonomy', ['id' => $id, 'taxonomy' => $taxonomy]) > 0;

        if ($deleted) {
            do_action('taxonomy.term_deleted', $term, $taxonomy);
            do_action('taxonomy.' . $taxonomy . '.term_deleted', $term);
        }

        return $deleted;
    }

    public function termsForContent(int $contentId, ?string $taxonomy = null): array
    {
        $params = ['id' => $contentId];
        $taxonomyClause = '';

        if ($taxonomy !== null) {
            $taxonomyClause = ' AND t.taxonomy = :taxonomy';
            $params['taxonomy'] = $this->key($taxonomy);
        }

        $rows = $this->db->select(
            'SELECT t.* FROM terms t INNER JOIN term_relationships r ON r.term_id = t.id WHERE r.content_id = :id' . $taxonomyClause . ' ORDER BY t.name ASC',
            $params
        );

        $terms = array_map(fn (array $row): array => $this->castTerm($row), $rows);

        if ($taxonomy !== null) {
            return $terms;
        }

        $grouped = [];

        foreach ($terms as $term) {
            $grouped[$term['taxonomy']][] = $term;
        }

        return $grouped;
    }

    private function hydrate(array $row): array
    {
        return $this->hydrateRows([$row], false)[0] ?? [];
    }

    private function hydrateMany(array $rows): array
    {
        return $this->hydrateRows($rows, !$this->db->fileMode());
    }

    private function hydrateRows(array $rows, bool $bulk): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (array $row): int => (int) $row['id'], $rows)));
        $metaById = $bulk ? $this->metaRowsFor($ids) : [];
        $termsById = $bulk ? $this->termRowsForContent($ids) : [];
        $items = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $schema = $this->requireType($row['type']);
            $fields = $this->defaultFields($schema);
            $metaRows = $bulk
                ? ($metaById[$id] ?? [])
                : $this->db->select('SELECT meta_key, meta_value FROM content_meta WHERE content_id = :id', ['id' => $id]);

            foreach ($metaRows as $meta) {
                $field = $schema['fields'][$meta['meta_key']] ?? ['type' => 'text'];
                $fields[$meta['meta_key']] = $this->castFromStorage($meta['meta_value'], $field['type']);
            }

            $content = [
                'id' => $id,
                'type' => $row['type'],
                'status' => $row['status'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'body' => $row['body'] ?? '',
                'excerpt' => $row['excerpt'] ?? '',
                'author_id' => $row['author_id'] ? (int) $row['author_id'] : null,
                'published_at' => $row['published_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'fields' => $fields,
                'terms' => $bulk ? ($termsById[$id] ?? []) : $this->termsForContent($id),
            ];

            $items[] = apply_filters('content.hydrate', $content, $schema);
        }

        return $items;
    }

    private function defaultFields(array $schema): array
    {
        $fields = [];

        foreach ($schema['fields'] as $name => $field) {
            $fields[$name] = $field['default'] ?? null;
        }

        return $fields;
    }

    private function metaRowsFor(array $ids): array
    {
        $ids = $this->sqlIds($ids);
        $rows = $this->db->select('SELECT content_id, meta_key, meta_value FROM content_meta WHERE content_id IN (' . implode(',', $ids) . ')');
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['content_id']][] = $row;
        }

        return $grouped;
    }

    private function termRowsForContent(array $ids): array
    {
        $ids = $this->sqlIds($ids);
        $rows = $this->db->select('SELECT r.content_id, t.* FROM terms t INNER JOIN term_relationships r ON r.term_id = t.id WHERE r.content_id IN (' . implode(',', $ids) . ') ORDER BY t.name ASC');
        $grouped = [];

        foreach ($rows as $row) {
            $contentId = (int) $row['content_id'];
            $term = $this->castTerm($row);
            $grouped[$contentId][$term['taxonomy']][] = $term;
        }

        return $grouped;
    }

    private function saveFields(int $contentId, string $type, array $data): void
    {
        $schema = $this->requireType($type);
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];

        foreach ($schema['fields'] as $name => $field) {
            $hasValue = array_key_exists($name, $fields) || array_key_exists($name, $data);

            if (!$hasValue) {
                continue;
            }

            $value = array_key_exists($name, $fields) ? $fields[$name] : $data[$name];
            $this->db->execute(
                'DELETE FROM content_meta WHERE content_id = :id AND meta_key = :key',
                ['id' => $contentId, 'key' => $name]
            );
            $this->db->insert('content_meta', [
                'content_id' => $contentId,
                'meta_key' => $name,
                'meta_value' => $this->castToStorage($value, $field['type']),
            ]);
        }
    }

    private function saveTerms(int $contentId, string $type, array $data): void
    {
        $terms = is_array($data['terms'] ?? null) ? $data['terms'] : [];
        $termNames = is_array($data['term_names'] ?? null) ? $data['term_names'] : [];

        foreach ($this->taxonomiesFor($type) as $taxonomy) {
            $name = $taxonomy['name'];

            if (!array_key_exists($name, $terms) && !array_key_exists($name, $termNames)) {
                continue;
            }

            $ids = array_map('intval', (array) ($terms[$name] ?? []));

            if (isset($termNames[$name])) {
                foreach ($this->parseNames((string) $termNames[$name]) as $termName) {
                    $ids[] = $this->term($name, $this->slug($termName))['id'] ?? $this->createTerm($name, ['name' => $termName])['id'];
                }
            }

            if ($name === 'category' && $ids === [] && ($default = $this->term('category', 'uncategorized'))) {
                $ids[] = $default['id'];
            }

            $this->assignTerms($contentId, $name, array_values(array_unique(array_filter($ids))));
        }

        do_action('content.terms_saved', $contentId, $type);
    }

    private function assignTerms(int $contentId, string $taxonomy, array $termIds): void
    {
        $taxonomy = $this->requireTaxonomy($taxonomy)['name'];
        $current = $this->termsForContent($contentId, $taxonomy);

        foreach ($current as $term) {
            $this->db->execute('DELETE FROM term_relationships WHERE content_id = :id AND term_id = :term_id', [
                'id' => $contentId,
                'term_id' => $term['id'],
            ]);
        }

        foreach ($termIds as $termId) {
            if (!$this->term($taxonomy, (int) $termId)) {
                continue;
            }

            $this->db->insert('term_relationships', [
                'content_id' => $contentId,
                'term_id' => (int) $termId,
            ]);
        }
    }

    private function pagePayload(array $data): array
    {
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $template = (string) ($data['template'] ?? $data['page_template'] ?? $fields['page_template'] ?? '');

        if ($template !== '') {
            $fields['page_template'] = trim($template);
        }

        return [
            'title' => (string) ($data['title'] ?? 'Untitled'),
            'slug' => (string) ($data['slug'] ?? ''),
            'body' => (string) ($data['body'] ?? $data['content'] ?? ''),
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
            'fields' => $fields,
        ];
    }

    private function validatePageTemplate(array $payload): void
    {
        $template = (string) (($payload['fields'] ?? [])['page_template'] ?? '');

        if ($template === '' || !function_exists('app') || !isset($GLOBALS['kivopress'])) {
            return;
        }

        if (!app()->theme()->validPageTemplate($template)) {
            throw new \InvalidArgumentException('Invalid page template: ' . $template);
        }
    }

    private function parseNames(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,]+/', $value) ?: []), fn (string $name): bool => $name !== ''));
    }

    private function termFilters(array $query): array
    {
        $terms = [];

        foreach ((array) ($query['terms'] ?? []) as $taxonomy => $termId) {
            $taxonomy = $this->key((string) $taxonomy);
            $termId = (int) $termId;

            if ($taxonomy !== '' && $termId > 0) {
                $terms[$taxonomy] = $termId;
            }
        }

        return $terms;
    }

    private function matchesTermFilters(array $item, array $terms): bool
    {
        foreach ($terms as $taxonomy => $termId) {
            $ids = array_map('intval', array_column($item['terms'][$taxonomy] ?? [], 'id'));

            if (!in_array((int) $termId, $ids, true)) {
                return false;
            }
        }

        return true;
    }

    private function castToStorage(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number' => (string) (float) $value,
            'boolean' => $value ? '1' : '0',
            'json', 'media' => json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private function castFromStorage(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'number' => (float) $value,
            'boolean' => $value === '1',
            'json', 'media' => json_decode($value, true),
            default => $value,
        };
    }

    private function castTerm(array $term): array
    {
        return [
            'id' => (int) $term['id'],
            'taxonomy' => (string) $term['taxonomy'],
            'slug' => (string) $term['slug'],
            'name' => (string) $term['name'],
            'parent_id' => $term['parent_id'] ? (int) $term['parent_id'] : null,
            'description' => (string) ($term['description'] ?? ''),
        ];
    }

    private function termCount(int $termId): int
    {
        $row = $this->db->first('SELECT COUNT(*) AS total FROM term_relationships WHERE term_id = :id', ['id' => $termId]);

        return (int) ($row['total'] ?? 0);
    }

    private function termCounts(array $termIds): array
    {
        $termIds = $this->sqlIds($termIds);

        if ($this->db->fileMode()) {
            $counts = [];

            foreach ($termIds as $termId) {
                $counts[$termId] = $this->termCount($termId);
            }

            return $counts;
        }

        $rows = $this->db->select('SELECT term_id, COUNT(*) AS total FROM term_relationships WHERE term_id IN (' . implode(',', $termIds) . ') GROUP BY term_id');
        $counts = array_fill_keys($termIds, 0);

        foreach ($rows as $row) {
            $counts[(int) $row['term_id']] = (int) $row['total'];
        }

        return $counts;
    }

    private function sqlIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0))) ?: [0];
    }

    private function parentId(string $taxonomy, mixed $parentId, ?int $ignoreId = null): ?int
    {
        $parentId = (int) $parentId;

        if ($parentId <= 0 || $parentId === $ignoreId) {
            return null;
        }

        return $this->term($taxonomy, $parentId) ? $parentId : null;
    }

    private function requireType(string $type): array
    {
        $type = $this->key($type);

        if (!$this->hasType($type)) {
            throw new \InvalidArgumentException("Unknown content type [{$type}].");
        }

        return $this->types[$type];
    }

    private function requireTaxonomy(string $taxonomy): array
    {
        $taxonomy = $this->key($taxonomy);

        if (!isset($this->taxonomies[$taxonomy])) {
            throw new \InvalidArgumentException("Unknown taxonomy [{$taxonomy}].");
        }

        return $this->taxonomies[$taxonomy];
    }

    private function uniqueSlug(string $type, string $slug, ?int $ignoreId = null): string
    {
        $base = $slug ?: 'item';
        $candidate = $base;
        $index = 2;

        while (true) {
            $params = ['type' => $type, 'slug' => $candidate];
            $ignore = '';

            if ($ignoreId) {
                $ignore = ' AND id != :id';
                $params['id'] = $ignoreId;
            }

            $existing = $this->db->first("SELECT id FROM content WHERE type = :type AND slug = :slug{$ignore} LIMIT 1", $params);

            if (!$existing) {
                return $candidate;
            }

            $candidate = $base . '-' . $index++;
        }
    }

    private function status(mixed $status): string
    {
        return in_array($status, ['draft', 'published', 'private'], true) ? $status : 'draft';
    }

    private function uniqueTermSlug(string $taxonomy, string $slug, ?int $ignoreId = null): string
    {
        $base = $slug ?: 'term';
        $candidate = $base;
        $index = 2;

        while (true) {
            $params = ['taxonomy' => $taxonomy, 'slug' => $candidate];
            $ignore = '';

            if ($ignoreId) {
                $ignore = ' AND id != :id';
                $params['id'] = $ignoreId;
            }

            $existing = $this->db->first("SELECT id FROM terms WHERE taxonomy = :taxonomy AND slug = :slug{$ignore} LIMIT 1", $params);

            if (!$existing) {
                return $candidate;
            }

            $candidate = $base . '-' . $index++;
        }
    }

    private function slug(mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: '';

        return trim($slug, '-') ?: 'item';
    }

    private function key(string $value): string
    {
        $key = strtolower(trim($value));
        $key = preg_replace('/[^a-z0-9_]+/i', '_', $key) ?: '';

        return trim($key, '_');
    }

    private function plural(string $name): string
    {
        if (str_ends_with($name, 's')) {
            return $name;
        }

        if (str_ends_with($name, 'y')) {
            return substr($name, 0, -1) . 'ies';
        }

        return $name . 's';
    }
}
