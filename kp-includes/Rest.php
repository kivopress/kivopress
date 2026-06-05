<?php

declare(strict_types=1);

namespace Kivopress;

final class Rest
{
    private array $routes = [];

    public function __construct(private App $app)
    {
    }

    public function registerRoute(string $namespace, string $route, array $args): void
    {
        $namespace = trim($namespace, '/');
        $route = '/' . trim($route, '/');
        $definitions = $this->isList($args) ? $args : [$args];

        foreach ($definitions as $definition) {
            if (!is_array($definition) || !isset($definition['callback']) || !is_callable($definition['callback'])) {
                throw new \InvalidArgumentException('REST route callback is required.');
            }

            foreach ($this->methods($definition['methods'] ?? 'GET') as $method) {
                $this->routes[] = [
                    'namespace' => $namespace,
                    'route' => $route,
                    'path' => $this->path($namespace, $route),
                    'method' => $method,
                    'callback' => $definition['callback'],
                    'permission_callback' => $definition['permission_callback'] ?? null,
                    'args' => is_array($definition['args'] ?? null) ? $definition['args'] : [],
                    'description' => (string) ($definition['description'] ?? ''),
                    'auth_required' => (bool) ($definition['auth_required'] ?? false),
                ];
            }
        }
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->routes as $index => $route) {
            $handler = function (mixed ...$values) use ($index): mixed {
                return $this->dispatch($this->routes[$index], $values);
            };

            $router->add($route['method'], $route['path'], $handler);
        }
    }

    public function routes(): array
    {
        return array_map(fn (array $route): array => [
            'namespace' => $route['namespace'],
            'route' => $route['route'],
            'path' => $route['path'],
            'method' => $route['method'],
            'description' => $route['description'],
            'args' => $route['args'],
            'auth_required' => $route['auth_required'],
        ], $this->routes);
    }

    public function index(): Response
    {
        return Response::json([
            'name' => $this->app->db()->getOption('site_name', 'Kivopress'),
            'namespaces' => array_values(array_unique(array_map(fn (array $route): string => $route['namespace'], $this->routes()))),
            'routes' => $this->routes(),
            'types' => array_map(fn (array $type): array => $this->typeSchema($type), $this->app->content()->apiTypes()),
            'taxonomies' => array_values(array_filter($this->app->content()->taxonomies(), fn (array $taxonomy): bool => (bool) ($taxonomy['api'] ?? true))),
        ]);
    }

    public function paginated(array $items, int $total, int $page, int $perPage, array $extra = []): Response
    {
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));

        return Response::json(array_merge([
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ], $extra))
            ->withHeader('X-KP-Total', (string) $total)
            ->withHeader('X-KP-TotalPages', (string) $totalPages);
    }

    public function request(array $params = [], array $route = []): array
    {
        $request = $this->app->request();

        return [
            'method' => $request->method(),
            'params' => $params,
            'query' => $request->query(),
            'body' => $request->body(),
            'headers' => $request->headers(),
            'ip' => $request->ip(),
            'route' => $route,
            'user' => $this->currentUser(),
        ];
    }

    public function typeSchema(array $type): array
    {
        return [
            'name' => $type['name'],
            'label' => $type['label'],
            'api_slug' => $type['api_slug'],
            'supports' => $type['supports'],
            'fields' => $type['fields'],
            'taxonomies' => $this->app->content()->taxonomiesFor($type['name']),
        ];
    }

    public function pageParams(array $query): array
    {
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? $query['limit'] ?? 25)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $offset = array_key_exists('offset', $query) ? max(0, (int) $query['offset']) : ($page - 1) * $perPage;

        return [$page, $perPage, $offset];
    }

    private function dispatch(array $route, array $values): mixed
    {
        $request = $this->request($this->namedParams($route['route'], $values), $route);
        $permission = $route['permission_callback'];
        $user = $request['user'] ?? null;

        if ($route['auth_required'] && !$user) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }

        if ($errors = $this->validateArgs($request, $route['args'])) {
            return Response::json(['error' => 'REST API validation failed.', 'details' => $errors], 422);
        }

        if (is_callable($permission)) {
            $allowed = $permission($request);

            if ($allowed instanceof Response) {
                return $allowed;
            }

            if ($allowed !== true) {
                return Response::json(['error' => 'REST API permission denied.'], $user ? 403 : 401);
            }
        }

        return $route['callback']($request);
    }

    public function currentUser(): ?array
    {
        return $this->app->auth()->apiUser() ?: $this->csrfSessionUser();
    }

    private function csrfSessionUser(): ?array
    {
        $user = $this->app->auth()->user();

        if (!$user) {
            return null;
        }

        $nonce = (string) (
            $this->app->request()->header('X-Kivopress-Nonce')
            ?? $this->app->request()->query('_kpnonce')
            ?? ''
        );

        return $this->app->auth()->validCsrf($nonce) ? $user : null;
    }

    private function path(string $namespace, string $route): string
    {
        return '/api/' . trim($namespace . '/' . trim($route, '/'), '/');
    }

    private function methods(mixed $methods): array
    {
        $methods = is_array($methods) ? $methods : preg_split('/[|,]/', (string) $methods);

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $method): string => strtoupper(trim((string) $method)),
            $methods ?: []
        ))));
    }

    private function namedParams(string $route, array $values): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}/', $route, $matches);
        $params = [];

        foreach ($matches[1] as $index => $name) {
            $params[$name] = $values[$index] ?? null;
        }

        return $params;
    }

    private function input(): array
    {
        return $this->app->request()->body();
    }

    private function validateArgs(array $request, array $args): array
    {
        $errors = [];
        $data = array_merge($request['query'], $request['body'], $request['params']);

        foreach ($args as $name => $rules) {
            $rules = is_array($rules) ? $rules : ['required' => (bool) $rules];
            $value = $data[$name] ?? null;

            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $errors[$name][] = 'This field is required.';
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (($rules['type'] ?? null) === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                $errors[$name][] = 'This field must be an integer.';
            }

            if (($rules['type'] ?? null) === 'string' && !is_scalar($value)) {
                $errors[$name][] = 'This field must be a string.';
            }

            if (isset($rules['enum']) && !in_array($value, (array) $rules['enum'], true)) {
                $errors[$name][] = 'This field has an invalid value.';
            }
        }

        return $errors;
    }

    private function isList(array $array): bool
    {
        return array_is_list($array) && isset($array[0]) && is_array($array[0]);
    }
}
