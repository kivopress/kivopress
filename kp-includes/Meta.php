<?php

declare(strict_types=1);

namespace Kivopress;

final class Meta
{
    public static function getContent(int $contentId, string $key = '', bool $single = false): mixed
    {
        return self::get('content_meta', 'content_id', $contentId, $key, $single);
    }

    public static function addContent(int $contentId, string $key, mixed $value, bool $unique = false): int|false
    {
        return self::add('content_meta', 'content_id', $contentId, $key, $value, $unique);
    }

    public static function updateContent(int $contentId, string $key, mixed $value, mixed $previous = null): int|bool
    {
        return self::update('content_meta', 'content_id', $contentId, $key, $value, $previous);
    }

    public static function deleteContent(int $contentId, string $key, mixed $value = null): bool
    {
        return self::delete('content_meta', 'content_id', $contentId, $key, $value);
    }

    public static function getUser(int $userId, string $key = '', bool $single = false): mixed
    {
        return self::get('user_meta', 'user_id', $userId, $key, $single);
    }

    public static function addUser(int $userId, string $key, mixed $value, bool $unique = false): int|false
    {
        return self::add('user_meta', 'user_id', $userId, $key, $value, $unique);
    }

    public static function updateUser(int $userId, string $key, mixed $value, mixed $previous = null): int|bool
    {
        return self::update('user_meta', 'user_id', $userId, $key, $value, $previous);
    }

    public static function deleteUser(int $userId, string $key, mixed $value = null): bool
    {
        return self::delete('user_meta', 'user_id', $userId, $key, $value);
    }

    public static function getTransient(string $key): mixed
    {
        $timeout = option('_transient_timeout_' . $key, 0);

        if ((int) $timeout > 0 && (int) $timeout < time()) {
            self::deleteTransient($key);

            return false;
        }

        return option('_transient_' . $key, false);
    }

    public static function setTransient(string $key, mixed $value, int $expiration = 0): bool
    {
        set_option('_transient_' . $key, $value);
        set_option('_transient_timeout_' . $key, $expiration > 0 ? time() + $expiration : 0);

        return true;
    }

    public static function deleteTransient(string $key): bool
    {
        db()->execute('DELETE FROM options WHERE option_key = :key', ['key' => '_transient_' . $key]);
        db()->execute('DELETE FROM options WHERE option_key = :key', ['key' => '_transient_timeout_' . $key]);

        return true;
    }

    public static function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_array($value) || is_object($value) ? serialize($value) : (string) $value;
    }

    public static function unserialize(mixed $value): mixed
    {
        if (!is_string($value) || !self::isSerialized($value)) {
            return $value;
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
    }

    public static function isSerialized(string $value): bool
    {
        $value = trim($value);

        return $value === 'N;' || preg_match('/^(a|O|s|i|b|d):/', $value) === 1;
    }

    private static function get(string $table, string $idColumn, int $id, string $key, bool $single): mixed
    {
        $rows = self::rows($table, $idColumn, $id, $key);

        if ($key === '') {
            $grouped = [];

            foreach ($rows as $row) {
                $grouped[(string) $row['meta_key']][] = self::unserialize($row['meta_value'] ?? null);
            }

            return $grouped;
        }

        $values = array_map(fn (array $row): mixed => self::unserialize($row['meta_value'] ?? null), $rows);

        return $single ? ($values[0] ?? '') : $values;
    }

    private static function add(string $table, string $idColumn, int $id, string $key, mixed $value, bool $unique = false): int|false
    {
        if ($id <= 0 || trim($key) === '') {
            return false;
        }

        if ($unique && self::get($table, $idColumn, $id, $key, false) !== []) {
            return false;
        }

        return db()->insert($table, [$idColumn => $id, 'meta_key' => $key, 'meta_value' => self::serialize($value)]) ?: false;
    }

    private static function update(string $table, string $idColumn, int $id, string $key, mixed $value, mixed $previous = null): int|bool
    {
        if ($id <= 0 || trim($key) === '') {
            return false;
        }

        if ($previous !== null && self::get($table, $idColumn, $id, $key, true) !== $previous) {
            return false;
        }

        $encoded = self::serialize($value);
        $existing = self::rows($table, $idColumn, $id, $key)[0] ?? null;

        if ($existing) {
            db()->update($table, ['meta_value' => $encoded], "{$idColumn} = :id AND meta_key = :key", ['id' => $id, 'key' => $key]);

            return true;
        }

        return db()->insert($table, [$idColumn => $id, 'meta_key' => $key, 'meta_value' => $encoded]);
    }

    private static function delete(string $table, string $idColumn, int $id, string $key, mixed $value = null): bool
    {
        $deleted = false;

        foreach (self::rows($table, $idColumn, $id, $key) as $row) {
            if ($value !== null && self::unserialize($row['meta_value'] ?? null) !== $value) {
                continue;
            }

            if (isset($row['id'])) {
                db()->execute("DELETE FROM {$table} WHERE id = :id", ['id' => (int) $row['id']]);
            } else {
                db()->execute("DELETE FROM {$table} WHERE {$idColumn} = :id AND meta_key = :key", ['id' => $id, 'key' => $key]);
            }

            $deleted = true;
        }

        return $deleted;
    }

    private static function rows(string $table, string $idColumn, int $id, string $key = ''): array
    {
        $sql = "SELECT * FROM {$table} WHERE {$idColumn} = :id" . ($key !== '' ? ' AND meta_key = :key' : '');
        $params = ['id' => $id] + ($key !== '' ? ['key' => $key] : []);

        return db()->select($sql, $params);
    }
}
