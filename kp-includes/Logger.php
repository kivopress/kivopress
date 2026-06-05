<?php

declare(strict_types=1);

namespace Kivopress;

final class Logger
{
    private const MAX_BYTES = 5242880;

    public function __construct(private string $rootPath)
    {
    }

    public function error(string $message, array $context = []): string
    {
        return $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): string
    {
        return $this->log('warning', $message, $context);
    }

    public function info(string $message, array $context = []): string
    {
        return $this->log('info', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): string
    {
        $path = $this->path();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->rotate($path);
        $eventId = (string) ($context['event_id'] ?? $this->eventId());
        unset($context['event_id']);

        $line = json_encode([
            'id' => $eventId,
            'time' => gmdate('c'),
            'level' => strtolower($level),
            'message' => $message,
            'fingerprint' => $this->fingerprint($level, $message, $context),
            'context' => $this->redact($this->normalize($context)),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        return $eventId;
    }

    public function recent(int $limit = 100): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -max(1, $limit));
        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function clear(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            file_put_contents($path, '');
        }
    }

    private function path(): string
    {
        return $this->rootPath . '/kp-content/logs/kivopress.log';
    }

    private function eventId(): string
    {
        return 'KP-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function fingerprint(string $level, string $message, array $context): string
    {
        return hash('sha256', strtolower($level) . '|' . $message . '|' . ($context['exception'] ?? '') . '|' . ($context['file'] ?? '') . '|' . ($context['line'] ?? ''));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            return $normalized;
        }

        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : $value::class;
        }

        if (is_resource($value)) {
            return 'resource';
        }

        return $value;
    }

    private function rotate(string $path): void
    {
        if (!is_file($path) || filesize($path) < self::MAX_BYTES) {
            return;
        }

        @rename($path, $path . '.' . gmdate('YmdHis'));
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/password|token|secret|authorization/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }

        return $context;
    }
}
