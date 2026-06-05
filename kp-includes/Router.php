<?php

declare(strict_types=1);

namespace Kivopress;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, array $middleware = []): void
    {
        foreach (explode('|', strtoupper($method)) as $verb) {
            $this->routes[] = [
                'method' => trim($verb),
                'path' => $this->normalize($path),
                'handler' => $handler,
                'middleware' => $middleware,
            ];
        }
    }

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request|string $request, ?string $path = null, ?Middleware $middleware = null): mixed
    {
        if (!$request instanceof Request) {
            $request = new Request($request, $path ?: '/');
        }

        $middleware ??= new Middleware();
        $method = $request->method();
        $routeMethod = $method === 'HEAD' ? 'GET' : $method;
        $path = $this->normalize($request->path());

        foreach ($this->routes as $route) {
            if ($route['method'] !== $routeMethod) {
                continue;
            }

            $params = $this->match($route['path'], $path);

            if ($params !== null) {
                $named = $this->namedParams($route['path'], $params);
                $routedRequest = $request->withRouteParams($named);

                return $middleware->handle(
                    $routedRequest,
                    fn (Request $request): mixed => ($route['handler'])(...$params),
                    $route['middleware']
                );
            }
        }

        return null;
    }

    private function normalize(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid route path.');
        }

        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private function match(string $routePath, string $actualPath): ?array
    {
        $pattern = '';
        $offset = 0;

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}/', $routePath, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            [$token, $position] = $match;
            $pattern .= preg_quote(substr($routePath, $offset, $position - $offset), '#');
            $pattern .= ($matches[2][$index][0] ?? '') === '*' ? '(.+)' : '([^/]+)';
            $offset = $position + strlen($token);
        }

        $pattern .= preg_quote(substr($routePath, $offset), '#');
        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $actualPath, $matches)) {
            return null;
        }

        array_shift($matches);

        return array_map('urldecode', $matches);
    }

    private function namedParams(string $routePath, array $values): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}/', $routePath, $matches);
        $params = [];

        foreach ($matches[1] as $index => $name) {
            $params[$name] = $values[$index] ?? null;
        }

        return $params;
    }
}
