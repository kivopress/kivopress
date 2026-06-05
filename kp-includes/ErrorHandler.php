<?php

declare(strict_types=1);

namespace Kivopress;

final class ErrorHandler
{
    private bool $registered = false;
    private bool $handledException = false;

    public function __construct(
        private App $app,
        private Logger $logger,
        private bool $debug = false
    ) {
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleUncaughtException']);
        register_shutdown_function([$this, 'handleShutdown']);
        $this->registered = true;
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $level = in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) ? 'error' : 'warning';
        $eventId = $this->logger->log($level, $message, $this->context([
            'kind' => 'php_error',
            'severity' => $severity,
            'file' => $file,
            'line' => $line,
        ]));

        if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            throw new \ErrorException($message . ' [' . $eventId . ']', 0, $severity, $file, $line);
        }

        return !$this->debug;
    }

    public function handleUncaughtException(\Throwable $exception): void
    {
        $this->handledException = true;
        $response = $this->exceptionResponse($exception, $this->isApiRequest());

        if (!headers_sent()) {
            $response->send();
            return;
        }

        echo $response->body();
    }

    public function handleShutdown(): void
    {
        if ($this->handledException) {
            return;
        }

        $error = error_get_last();

        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $eventId = $this->logger->error($error['message'], $this->context([
            'kind' => 'fatal_error',
            'severity' => $error['type'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]));

        if (headers_sent()) {
            return;
        }

        $this->fatalResponse($eventId, $this->isApiRequest())->send();
    }

    public function exceptionResponse(\Throwable $exception, bool $api): Response
    {
        $status = $exception instanceof \InvalidArgumentException ? 404 : 500;
        $eventId = $this->report($exception, ['kind' => 'exception']);

        if ($api) {
            return Response::json([
                'error' => $status >= 500 && !$this->debug ? 'Server error.' : $exception->getMessage(),
                'event_id' => $eventId,
            ], $status);
        }

        if (!$this->debug) {
            return $this->fatalResponse($eventId, $api, $status);
        }

        return Response::html(
            '<h1>Kivopress Error</h1><p>Event ID: <code>' . $this->escape($eventId) . '</code></p><pre>' .
            $this->escape($exception->getMessage() . "\n\n" . $exception->getTraceAsString()) .
            '</pre>',
            $status
        );
    }

    public function report(\Throwable $exception, array $context = []): string
    {
        return $this->logger->error($exception->getMessage(), $this->context($context + [
            'exception' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice($exception->getTrace(), 0, 20),
        ]));
    }

    private function fatalResponse(string $eventId, bool $api, int $status = 500): Response
    {
        if ($api) {
            return Response::json([
                'error' => 'Server error.',
                'event_id' => $eventId,
            ], $status);
        }

        return Response::html('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kivopress Error</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f7f4;color:#101f1a;margin:0;padding:48px}.kp-error{background:#fff;border:1px solid #d8e1dc;border-radius:8px;max-width:680px;padding:24px}code{background:#edf5ef;border-radius:4px;padding:2px 5px}</style></head><body><section class="kp-error"><h1>Something went wrong.</h1><p>Kivopress caught the problem and logged it for review.</p><p>Event ID: <code>' . $this->escape($eventId) . '</code></p></section></body></html>', $status);
    }

    private function context(array $extra = []): array
    {
        $request = null;

        try {
            $request = $this->app->request();
        } catch (\Throwable) {
            $request = null;
        }

        $user = null;

        try {
            $user = $this->app->auth()->user();
        } catch (\Throwable) {
            $user = null;
        }

        return array_filter([
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'path' => $request ? $request->path() : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_id' => is_array($user) ? ($user['id'] ?? null) : null,
        ] + $extra, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return str_starts_with($path, '/api');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
