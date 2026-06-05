<?php

declare(strict_types=1);

namespace Kivopress;

final class RateLimiter
{
    private const CACHE_DIR = '/kp-content/cache/rate-limits';

    public function __construct(private string $rootPath)
    {
    }

    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $hash = hash('sha256', $key);

        return $this->apcuAvailable()
            ? $this->attemptApcu($hash, $maxAttempts, $windowSeconds, $now)
            : $this->attemptFile($hash, $maxAttempts, $windowSeconds, $now);
    }

    public function clearExpired(): void
    {
        $now = time();
        $dir = $this->cacheDir();

        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $bucket = json_decode((string) @file_get_contents($file), true);

            if (!is_array($bucket) || (int) ($bucket['reset_at'] ?? 0) <= $now) {
                @unlink($file);
            }
        }
    }

    private function response(array $bucket, int $maxAttempts, int $now): array
    {
        $remaining = max(0, $maxAttempts - (int) $bucket['count']);

        return [
            'allowed' => (int) $bucket['count'] <= $maxAttempts,
            'limit' => $maxAttempts,
            'remaining' => $remaining,
            'retry_after' => max(0, (int) $bucket['reset_at'] - $now),
            'reset_at' => (int) $bucket['reset_at'],
        ];
    }

    private function attemptApcu(string $hash, int $maxAttempts, int $windowSeconds, int $now): array
    {
        $countKey = 'kivopress_rate_count_' . $hash;
        $resetKey = 'kivopress_rate_reset_' . $hash;
        $resetAt = (int) apcu_fetch($resetKey);

        if ($resetAt <= $now) {
            $resetAt = $now + $windowSeconds;
            apcu_store($resetKey, $resetAt, $windowSeconds);
            apcu_store($countKey, 0, $windowSeconds);
        }

        $success = false;
        $count = apcu_inc($countKey, 1, $success, $windowSeconds);

        if (!$success) {
            $count = 1;
            apcu_store($countKey, $count, max(1, $resetAt - $now));
        }

        return $this->response(['count' => $count, 'reset_at' => $resetAt], $maxAttempts, $now);
    }

    private function attemptFile(string $hash, int $maxAttempts, int $windowSeconds, int $now): array
    {
        $dir = $this->cacheDir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->response(['count' => 1, 'reset_at' => $now + $windowSeconds], $maxAttempts, $now);
        }

        $handle = @fopen($dir . '/' . $hash . '.json', 'c+b');

        if (!$handle) {
            return $this->response(['count' => 1, 'reset_at' => $now + $windowSeconds], $maxAttempts, $now);
        }

        flock($handle, LOCK_EX);
        rewind($handle);
        $contents = stream_get_contents($handle);
        $bucket = json_decode($contents === false ? '' : $contents, true);

        if (!is_array($bucket) || (int) ($bucket['reset_at'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($bucket, JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $this->response($bucket, $maxAttempts, $now);
    }

    private function cacheDir(): string
    {
        return $this->rootPath . self::CACHE_DIR;
    }

    private function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch') && (bool) ini_get('apc.enabled');
    }
}
