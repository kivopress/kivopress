<?php

declare(strict_types=1);

namespace Kivopress;

final class Middleware
{
    private array $global = [];
    private array $aliases = [];

    public function add(callable|string $middleware): void
    {
        $this->global[] = $middleware;
    }

    public function alias(string $name, callable|string $middleware): void
    {
        $this->aliases[$name] = $middleware;
    }

    public function handle(Request $request, callable $destination, array $routeMiddleware = []): mixed
    {
        $stack = array_merge($this->global, $routeMiddleware);
        $pipeline = array_reduce(
            array_reverse($stack),
            fn (callable $next, callable|string $entry): callable => function (Request $request) use ($entry, $next): mixed {
                $middleware = $this->resolve($entry);

                return $middleware($request, $next);
            },
            $destination
        );

        return $pipeline($request);
    }

    private function resolve(callable|string $entry): callable
    {
        if (is_callable($entry)) {
            return $entry;
        }

        [$name, $parameterString] = array_pad(explode(':', $entry, 2), 2, '');
        $middleware = $this->aliases[$name] ?? null;

        if (!$middleware) {
            throw new \InvalidArgumentException("Unknown middleware [{$name}].");
        }

        $resolved = is_string($middleware) ? $this->resolve($middleware) : $middleware;
        $parameters = $parameterString === '' ? [] : array_map('trim', explode(',', $parameterString));

        return static fn (Request $request, callable $next): mixed => $resolved($request, $next, ...$parameters);
    }
}
