<?php

declare(strict_types=1);

namespace Kivopress;

final class Request
{
    private ?array $json = null;

    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $files = [],
        private array $cookies = [],
        private array $server = [],
        private string $rawBody = '',
        private array $routeParams = []
    ) {
        $this->method = strtoupper($this->method);
        $this->path = '/' . trim(parse_url($this->path, PHP_URL_PATH) ?: '/', '/');
        $this->path = $this->path === '//' ? '/' : $this->path;
    }

    public static function capture(?string $method = null, ?string $path = null): self
    {
        return new self(
            $method ?: ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path ?: (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            $_GET,
            $_POST,
            $_FILES,
            $_COOKIE,
            $_SERVER,
            file_get_contents('php://input') ?: ''
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);

        return $clone;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api');
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    public function files(?string $key = null): mixed
    {
        return $key === null ? $this->files : ($this->files[$key] ?? null);
    }

    public function body(?string $key = null, mixed $default = null): mixed
    {
        $body = $this->json();

        if ($body === []) {
            $body = $this->post;
        }

        return $key === null ? $body : ($body[$key] ?? $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = array_replace($this->query, $this->body());

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        if (!str_contains($this->header('Content-Type', ''), 'application/json') || trim($this->rawBody) === '') {
            return $this->json = [];
        }

        $decoded = json_decode($this->rawBody, true);

        return $this->json = is_array($decoded) ? $decoded : [];
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function headers(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        }

        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($this->server[$key])) {
                $headers[$name] = $this->server[$key];
            }
        }

        return $headers;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $normalized = strtolower($name);

        foreach ($this->headers() as $header => $value) {
            if (strtolower($header) === $normalized) {
                return $value;
            }
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = (string) ($this->header('Authorization') ?? $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/Bearer\s+(.+)/i', $header, $match)) {
            return trim($match[1]);
        }

        $token = trim((string) $this->header('X-Kivopress-Token', ''));

        return $token !== '' ? $token : null;
    }

    public function ip(): string
    {
        $forwarded = trim(explode(',', (string) $this->header('X-Forwarded-For', ''))[0]);

        return $forwarded !== '' ? $forwarded : (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function routeParams(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->routeParams : ($this->routeParams[$key] ?? $default);
    }

    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }
}
