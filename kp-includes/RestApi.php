<?php

declare(strict_types=1);

namespace Kivopress;

final class RestApi
{
    public function __construct(private App $app)
    {
    }

    public function register(): void
    {
        $rest = $this->app->rest();

        $rest->registerRoute('kp/v1', '/', [
            'methods' => 'GET',
            'description' => 'Kivopress v1 namespace index.',
            'callback' => fn (): Response => Response::json([
                'namespace' => 'kp/v1',
                'routes' => array_values(array_filter($rest->routes(), fn (array $route): bool => $route['namespace'] === 'kp/v1')),
            ]),
        ]);

        foreach (['kp/v1' => 'Kivopress v1', '' => 'Short alias'] as $namespace => $group) {
            $this->registerTaxonomyRoutes($namespace, $group);
            $this->registerMediaRoutes($namespace, $group);
            $this->registerContentRoutes($namespace, $group);
        }
    }

    private function registerTaxonomyRoutes(string $namespace, string $group): void
    {
        $this->app->rest()->registerRoute($namespace, '/taxonomies', [
            'methods' => 'GET',
            'description' => $group . ' taxonomy collection.',
            'callback' => fn (): Response => Response::json(['data' => array_values($this->app->content()->taxonomies())]),
        ]);

        $this->app->rest()->registerRoute($namespace, '/taxonomies/{taxonomy}', [
            'methods' => 'GET',
            'description' => $group . ' terms for a taxonomy.',
            'args' => ['taxonomy' => ['required' => true]],
            'callback' => fn (array $request): Response => $this->terms((string) $request['params']['taxonomy']),
        ]);
    }

    private function registerMediaRoutes(string $namespace, string $group): void
    {
        $this->app->rest()->registerRoute($namespace, '/media', [
            [
                'methods' => 'GET',
                'description' => $group . ' media collection with page, per_page, search, type, and offset query params.',
                'callback' => fn (): Response => $this->mediaList(),
            ],
            [
                'methods' => 'POST',
                'description' => $group . ' upload one or more media files. Requires API token with upload_media.',
                'auth_required' => true,
                'permission_callback' => fn (): bool => $this->can('upload_media'),
                'callback' => fn (): Response => $this->mediaUpload(),
            ],
        ]);

        $this->app->rest()->registerRoute($namespace, '/media/{id}', [
            [
                'methods' => 'GET',
                'description' => $group . ' media item by ID.',
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
                'callback' => fn (array $request): Response => $this->mediaShow((int) $request['params']['id']),
            ],
            [
                'methods' => 'PATCH',
                'description' => $group . ' update media title, alt, and caption. Requires API token with manage_media.',
                'auth_required' => true,
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
                'permission_callback' => fn (): bool => $this->can('manage_media'),
                'callback' => fn (array $request): Response => $this->mediaUpdate((int) $request['params']['id']),
            ],
            [
                'methods' => 'DELETE',
                'description' => $group . ' delete media. Requires API token with manage_media.',
                'auth_required' => true,
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
                'permission_callback' => fn (): bool => $this->can('manage_media'),
                'callback' => fn (array $request): Response => $this->mediaDelete((int) $request['params']['id']),
            ],
        ]);
    }

    private function registerContentRoutes(string $namespace, string $group): void
    {
        $this->app->rest()->registerRoute($namespace, '/{type}', [
            [
                'methods' => 'GET',
                'description' => $group . ' content collection with page, per_page, search, status, and offset query params.',
                'args' => ['type' => ['required' => true]],
                'callback' => fn (array $request): Response => $this->list((string) $request['params']['type']),
            ],
            [
                'methods' => 'POST',
                'description' => $group . ' create content. Requires API token with edit capability for the content type.',
                'auth_required' => true,
                'args' => ['type' => ['required' => true]],
                'permission_callback' => fn (array $request): bool => $this->canContent((string) $request['params']['type'], 'create', $request),
                'callback' => fn (array $request): Response => $this->create((string) $request['params']['type']),
            ],
        ]);

        $this->app->rest()->registerRoute($namespace, '/{type}/{id}', [
            [
                'methods' => 'GET',
                'description' => $group . ' single content item by ID or slug.',
                'args' => ['type' => ['required' => true], 'id' => ['required' => true]],
                'callback' => fn (array $request): Response => $this->show((string) $request['params']['type'], (string) $request['params']['id']),
            ],
            [
                'methods' => 'PATCH',
                'description' => $group . ' update content. Requires API token with edit capability for the content type.',
                'auth_required' => true,
                'permission_callback' => fn (array $request): bool => $this->canContent((string) $request['params']['type'], 'update', $request),
                'callback' => fn (array $request): Response => $this->update((string) $request['params']['type'], (string) $request['params']['id']),
            ],
            [
                'methods' => 'DELETE',
                'description' => $group . ' delete content. Requires API token with delete capability for the content type.',
                'auth_required' => true,
                'permission_callback' => fn (array $request): bool => $this->canContent((string) $request['params']['type'], 'delete'),
                'callback' => fn (array $request): Response => $this->delete((string) $request['params']['type'], (string) $request['params']['id']),
            ],
        ]);
    }

    private function list(string $apiSlug): Response
    {
        $type = $this->typeOrFail($apiSlug);
        [$page, $perPage, $offset] = $this->app->rest()->pageParams($_GET);
        $query = [
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null,
            'limit' => $perPage,
            'offset' => $offset,
            'include_drafts' => (bool) $this->app->auth()->apiUser(),
        ];
        $items = $this->app->content()->all($type['name'], $query);
        $total = $this->app->content()->countAll($type['name'], $query);

        return $this->app->rest()->paginated($items, $total, $page, $perPage, [
            'type' => $this->app->rest()->typeSchema($type),
        ]);
    }

    private function show(string $apiSlug, string $id): Response
    {
        $type = $this->typeOrFail($apiSlug);
        $item = $this->app->content()->find($type['name'], $id, (bool) $this->app->auth()->apiUser());

        return $item
            ? Response::json(['data' => $item])
            : Response::json(['error' => 'Content not found.'], 404);
    }

    private function create(string $apiSlug): Response
    {
        $user = $this->apiUserOrFail();

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->typeOrFail($apiSlug);
        $item = $this->app->content()->create($type['name'], $this->input(), $user['id']);

        return Response::json(['data' => $item], 201);
    }

    private function update(string $apiSlug, string $id): Response
    {
        $user = $this->apiUserOrFail();

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->typeOrFail($apiSlug);
        $item = $this->app->content()->update($type['name'], (int) $id, $this->input());

        return $item
            ? Response::json(['data' => $item])
            : Response::json(['error' => 'Content not found.'], 404);
    }

    private function delete(string $apiSlug, string $id): Response
    {
        $user = $this->apiUserOrFail();

        if ($user instanceof Response) {
            return $user;
        }

        $type = $this->typeOrFail($apiSlug);

        return $this->app->content()->delete($type['name'], (int) $id)
            ? Response::json(['deleted' => true])
            : Response::json(['error' => 'Content not found.'], 404);
    }

    private function terms(string $taxonomy): Response
    {
        $schema = $this->app->content()->taxonomy($taxonomy);

        if (!$schema || !($schema['api'] ?? true)) {
            return Response::json(['error' => 'Taxonomy not found.'], 404);
        }

        return Response::json([
            'taxonomy' => $schema,
            'data' => $this->app->content()->terms($schema['name']),
        ]);
    }

    private function mediaList(): Response
    {
        [$page, $perPage, $offset] = $this->app->rest()->pageParams($_GET);
        $query = [
            'type' => $_GET['type'] ?? '',
            'search' => $_GET['search'] ?? '',
            'limit' => $perPage,
            'offset' => $offset,
        ];

        return $this->app->rest()->paginated(
            $this->app->media()->all($query),
            $this->app->media()->count($query),
            $page,
            $perPage,
            ['types' => ['image', 'audio', 'video', 'document']]
        );
    }

    private function mediaShow(int $id): Response
    {
        $item = $this->app->media()->find($id);

        return $item
            ? Response::json(['data' => $item])
            : Response::json(['error' => 'Media not found.'], 404);
    }

    private function mediaUpload(): Response
    {
        $user = $this->apiUserOrFail();

        if ($user instanceof Response) {
            return $user;
        }

        $files = $this->mediaUploadFiles();

        if ($files === []) {
            return Response::json(['error' => 'Upload a file using multipart/form-data with field name file or media.'], 422);
        }

        $result = $this->app->media()->uploadMany($files, (int) $user['id']);

        if ($result['created'] === [] && $result['errors'] !== []) {
            return Response::json(['error' => 'Media upload failed.', 'details' => $result['errors']], 422);
        }

        return Response::json([
            'data' => $result['created'],
            'errors' => $result['errors'],
        ], 201);
    }

    private function mediaUpdate(int $id): Response
    {
        $payload = $this->app->request()->body();
        $data = [];

        foreach (['title', 'alt', 'caption'] as $field) {
            if (array_key_exists($field, $payload)) {
                $data[$field] = \sanitize_text_field($payload[$field]);
            }
        }

        $item = $this->app->media()->update($id, $data);

        return $item
            ? Response::json(['data' => $item])
            : Response::json(['error' => 'Media not found.'], 404);
    }

    private function mediaDelete(int $id): Response
    {
        return $this->app->media()->delete($id)
            ? Response::json(['deleted' => true])
            : Response::json(['error' => 'Media not found.'], 404);
    }

    private function typeOrFail(string $apiSlug): array
    {
        $type = $this->app->content()->typeFromApiSlug($apiSlug);

        if (!$type) {
            throw new \InvalidArgumentException('Unknown API content type.');
        }

        return $type;
    }

    private function apiUserOrFail(): array|Response
    {
        $user = $this->app->auth()->apiUser();

        return $user ?: Response::json(['error' => 'API token required.'], 401);
    }

    private function can(string $capability): bool
    {
        $user = $this->app->auth()->apiUser();

        return $user ? $this->app->auth()->can($capability, $user) : false;
    }

    private function canContent(string $apiSlug, string $action, array $request = []): bool
    {
        $user = $this->app->auth()->apiUser();

        if (!$user) {
            return false;
        }

        try {
            $type = $this->typeOrFail($apiSlug);
        } catch (\Throwable) {
            return false;
        }

        $capability = match ($action) {
            'delete' => 'delete_' . $type['api_slug'],
            default => 'edit_' . $type['api_slug'],
        };

        if (!$this->app->auth()->can($capability, $user)) {
            return false;
        }

        if (($request['body']['status'] ?? null) === 'published' && !$this->app->auth()->can('publish_' . $type['api_slug'], $user)) {
            return false;
        }

        return true;
    }

    private function mediaUploadFiles(): array
    {
        $files = $this->app->request()->files();

        if (!is_array($files) || $files === []) {
            return [];
        }

        foreach (['file', 'media'] as $key) {
            if (isset($files[$key]) && is_array($files[$key])) {
                return $files[$key];
            }
        }

        foreach ($files as $file) {
            if (is_array($file)) {
                return $file;
            }
        }

        return [];
    }

    private function input(): array
    {
        return $this->app->request()->body();
    }
}
