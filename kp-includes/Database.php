<?php

declare(strict_types=1);

namespace Kivopress;

use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;
    private bool $fileMode = false;
    private string $filePath;
    private array $store = [];
    private array $optionCache = [];

    public function __construct(private array $config)
    {
        $driver = $this->config['driver'] ?? 'sqlite';
        $drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        $this->fileMode = $driver === 'file' || ($driver === 'sqlite' && !in_array('sqlite', $drivers, true));
        $basePath = $this->config['path'] ?? dirname(__DIR__) . '/kp-content/data/kivopress.sqlite';
        $this->filePath = dirname($basePath) . '/kivopress.json';
    }

    public function pdo(): PDO
    {
        if ($this->fileMode) {
            throw new \RuntimeException('PDO driver is unavailable; Kivopress is using file storage.');
        }

        if ($this->pdo) {
            return $this->pdo;
        }

        $driver = $this->config['driver'] ?? 'sqlite';

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException("The pdo_{$driver} PHP extension is not available.");
        }

        if ($driver === 'sqlite') {
            $path = $this->config['path'];
            $dir = dirname($path);

            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $dsn = 'sqlite:' . $path;
            $this->pdo = new PDO($dsn);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $charset = $this->config['charset'] ?? 'utf8mb4';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['name'],
                $charset
            );

            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['password']);
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $this->pdo;
    }

    public function migrate(): void
    {
        if ($this->fileMode) {
            $this->migrateFile();

            return;
        }

        $id = $this->isMysql() ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = $this->isMysql() ? 'LONGTEXT' : 'TEXT';
        $string = $this->isMysql() ? 'VARCHAR(191)' : 'TEXT';

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS options (
            option_key {$string} PRIMARY KEY,
            option_value {$text} NULL,
            autoload INTEGER NOT NULL DEFAULT 1
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS content (
            id {$id},
            type {$string} NOT NULL,
            status {$string} NOT NULL DEFAULT 'draft',
            slug {$string} NOT NULL,
            title {$text} NOT NULL,
            body {$text} NULL,
            excerpt {$text} NULL,
            author_id INTEGER NULL,
            published_at {$string} NULL,
            created_at {$string} NOT NULL,
            updated_at {$string} NOT NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS content_meta (
            id {$id},
            content_id INTEGER NOT NULL,
            meta_key {$string} NOT NULL,
            meta_value {$text} NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS taxonomies (
            name {$string} PRIMARY KEY,
            label {$string} NOT NULL,
            content_types {$text} NOT NULL,
            hierarchical INTEGER NOT NULL DEFAULT 0,
            config {$text} NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS terms (
            id {$id},
            taxonomy {$string} NOT NULL,
            slug {$string} NOT NULL,
            name {$string} NOT NULL,
            parent_id INTEGER NULL,
            description {$text} NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS term_relationships (
            content_id INTEGER NOT NULL,
            term_id INTEGER NOT NULL,
            PRIMARY KEY (content_id, term_id)
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS users (
            id {$id},
            name {$string} NOT NULL,
            email {$string} NOT NULL,
            password_hash {$string} NOT NULL,
            role {$string} NOT NULL DEFAULT 'admin',
            api_token_hash {$string} NULL,
            created_at {$string} NOT NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS user_meta (
            id {$id},
            user_id INTEGER NOT NULL,
            meta_key {$string} NOT NULL,
            meta_value {$text} NULL
        )");

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS api_tokens (
            id {$id},
            user_id INTEGER NOT NULL,
            name {$string} NOT NULL,
            token_lookup {$string} NULL,
            token_hash {$string} NOT NULL,
            abilities {$text} NULL,
            expires_at {$string} NULL,
            last_used_at {$string} NULL,
            revoked_at {$string} NULL,
            rotated_at {$string} NULL,
            created_at {$string} NOT NULL
        )");

        if (!$this->hasColumn('api_tokens', 'token_lookup')) {
            $this->addColumn('api_tokens', "token_lookup {$string} NULL");
        }

        $this->pdo()->exec("CREATE TABLE IF NOT EXISTS media (
            id {$id},
            filename {$string} NOT NULL,
            original_name {$string} NOT NULL,
            disk_path {$text} NOT NULL,
            mime {$string} NOT NULL,
            extension {$string} NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            width INTEGER NULL,
            height INTEGER NULL,
            title {$text} NOT NULL,
            alt {$text} NULL,
            caption {$text} NULL,
            uploaded_by INTEGER NULL,
            created_at {$string} NOT NULL,
            updated_at {$string} NOT NULL
        )");

        $this->createIndex('idx_content_type_slug', 'content', ['type', 'slug'], true);
        $this->createIndex('idx_content_meta_lookup', 'content_meta', ['content_id', 'meta_key']);
        $this->createIndex('idx_terms_taxonomy_slug', 'terms', ['taxonomy', 'slug'], true);
        $this->createIndex('idx_term_relationships_term', 'term_relationships', ['term_id']);
        $this->createIndex('idx_users_email', 'users', ['email'], true);
        $this->createIndex('idx_user_meta_lookup', 'user_meta', ['user_id', 'meta_key']);
        $this->createIndex('idx_api_tokens_user', 'api_tokens', ['user_id']);
        $this->createIndex('idx_api_tokens_lookup', 'api_tokens', ['token_lookup']);
        $this->createIndex('idx_media_created', 'media', ['created_at']);

        if ($this->getOption('site_name') === null) {
            $this->setOption('site_name', 'Kivopress');
        }
    }

    public function select(string $sql, array $params = []): array
    {
        if ($this->fileMode) {
            return $this->fileSelect($sql, $params);
        }

        return $this->statement($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        if ($this->fileMode) {
            $rows = $this->fileSelect($sql, $params);

            return $rows[0] ?? null;
        }

        $row = $this->statement($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->fileMode) {
            return $this->fileExecute($sql, $params);
        }

        return $this->statement($sql, $params)->rowCount();
    }

    public function insert(string $table, array $values): int
    {
        $table = $this->identifier($table);

        if ($this->fileMode) {
            $this->loadFile();
            $this->store[$table] ??= [];
            $row = $values;

            if (isset($this->store['_ids'][$table]) && !isset($row['id'])) {
                $row['id'] = $this->nextId($table);
            }

            $this->store[$table][] = $row;
            $this->saveFile();

            return (int) ($row['id'] ?? 0);
        }

        $columns = array_map(fn (string $column): string => $this->identifier($column), array_keys($values));
        $placeholders = array_map(fn (string $column): string => ':' . $column, $columns);

        $this->statement(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $values
        );

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $values, string $where, array $params): int
    {
        $table = $this->identifier($table);

        if ($this->fileMode) {
            $this->loadFile();
            $count = 0;

            foreach ($this->store[$table] as &$row) {
                if (!$this->matchesWhere($row, $where, $params)) {
                    continue;
                }

                foreach ($values as $column => $value) {
                    $row[$column] = $value;
                }

                $count++;
            }

            unset($row);
            $this->saveFile();

            return $count;
        }

        $sets = [];
        $bound = [];

        foreach ($values as $column => $value) {
            $column = $this->identifier((string) $column);
            $key = 'set_' . $column;
            $sets[] = $column . ' = :' . $key;
            $bound[$key] = $value;
        }

        return $this->execute(
            'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            array_merge($bound, $params)
        );
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->optionCache)) {
            return $this->optionCache[$key];
        }

        $row = $this->first('SELECT option_value FROM options WHERE option_key = :key', ['key' => $key]);

        if (!$row) {
            return $default;
        }

        return $this->optionCache[$key] = json_decode($row['option_value'], true);
    }

    public function setOption(string $key, mixed $value, bool $autoload = true): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $this->optionCache[$key] = $value;
        $row = [
            'option_value' => $encoded,
            'autoload' => $autoload ? 1 : 0,
        ];

        if ($this->first('SELECT option_key FROM options WHERE option_key = :key LIMIT 1', ['key' => $key])) {
            $this->update('options', $row, 'option_key = :key', ['key' => $key]);

            return;
        }

        try {
            $this->insert('options', ['option_key' => $key] + $row);
        } catch (\Throwable) {
            $this->update('options', $row, 'option_key = :key', ['key' => $key]);
        }
    }

    public function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function fileMode(): bool
    {
        return $this->fileMode;
    }

    public function driver(): string
    {
        return $this->fileMode ? 'file' : (string) ($this->config['driver'] ?? 'sqlite');
    }

    public function prefix(): string
    {
        $prefix = (string) ($this->config['prefix'] ?? 'kp_');

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('Invalid database prefix.');
        }

        return $prefix;
    }

    public function isMysql(): bool
    {
        return $this->driver() === 'mysql';
    }

    public function hasColumn(string $table, string $column): bool
    {
        if ($this->fileMode) {
            return true;
        }

        $table = $this->identifier($table);
        $column = $this->identifier($column);

        if ($this->isMysql()) {
            return (bool) $this->first(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column LIMIT 1',
                ['table' => $table, 'column' => $column]
            );
        }

        foreach ($this->select("PRAGMA table_info({$table})") as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function addColumn(string $table, string $definition): void
    {
        if ($this->fileMode) {
            return;
        }

        $table = $this->identifier($table);
        $this->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }

    public function ensureIndex(string $name, string $table, array $columns, bool $unique = false): void
    {
        $this->createIndex($name, $table, $columns, $unique);
    }

    public function dropIndex(string $name, string $table): void
    {
        if ($this->fileMode) {
            return;
        }

        $name = $this->identifier($name);
        $table = $this->identifier($table);
        $sql = $this->isMysql()
            ? "DROP INDEX {$name} ON {$table}"
            : "DROP INDEX IF EXISTS {$name}";

        try {
            $this->pdo()->exec($sql);
        } catch (\Throwable) {
            // Missing indexes are harmless during idempotent migrations.
        }
    }

    public function tableExists(string $table): bool
    {
        $table = $this->identifier($table);

        if ($this->fileMode) {
            $this->loadFile();

            return array_key_exists($table, $this->store);
        }

        if ($this->isMysql()) {
            return (bool) $this->first(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
                ['table' => $table]
            );
        }

        return (bool) $this->first(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1",
            ['table' => $table]
        );
    }

    public function ensureFileTable(string $table, bool $autoIncrement = true): void
    {
        $table = $this->identifier($table);

        if (!$this->fileMode) {
            return;
        }

        $this->loadFile();
        $this->store[$table] ??= [];

        if ($autoIncrement) {
            $this->store['_ids'][$table] ??= 1;
        }

        $this->saveFile();
    }

    private function statement(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . ltrim((string) $key, ':'), $value);
        }

        $statement->execute();

        return $statement;
    }

    private function createIndex(string $name, string $table, array $columns, bool $unique = false): void
    {
        if ($this->fileMode) {
            return;
        }

        $name = $this->identifier($name);
        $table = $this->identifier($table);
        $prefix = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        $columns = implode(', ', array_map(fn (string $column): string => $this->identifier($column), $columns));
        $ifNotExists = $this->isMysql() ? '' : ' IF NOT EXISTS';

        try {
            $this->pdo()->exec("{$prefix}{$ifNotExists} {$name} ON {$table} ({$columns})");
        } catch (\Throwable) {
            // Duplicate index errors are harmless here.
        }
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException("Invalid database identifier [{$value}].");
        }

        return $value;
    }

    private function migrateFile(): void
    {
        $dir = dirname($this->filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->loadFile();

        foreach (['options', 'content', 'content_meta', 'taxonomies', 'terms', 'term_relationships', 'users', 'user_meta', 'api_tokens', 'media'] as $table) {
            $this->store[$table] ??= [];
        }

        $this->store['_ids'] ??= [
            'content' => 1,
            'content_meta' => 1,
            'terms' => 1,
            'users' => 1,
            'user_meta' => 1,
            'api_tokens' => 1,
            'media' => 1,
        ];
        $this->store['_ids']['user_meta'] ??= 1;
        $this->store['_ids']['api_tokens'] ??= 1;
        $this->store['_ids']['media'] ??= 1;

        $this->saveFile();

        if ($this->getOption('site_name') === null) {
            $this->setOption('site_name', 'Kivopress');
        }
    }

    private function fileSelect(string $sql, array $params): array
    {
        $this->loadFile();
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if (str_contains($normalized, 'FROM options')) {
            return array_values(array_filter(
                $this->store['options'],
                fn (array $row): bool => ($row['option_key'] ?? null) === ($params['key'] ?? null)
            ));
        }

        if (str_contains($normalized, 'COUNT(*) AS total FROM users')) {
            $rows = $this->store['users'];

            if (array_key_exists('role', $params)) {
                $rows = array_filter($rows, fn (array $row): bool => ($row['role'] ?? null) === $params['role']);
            }

            return [['total' => count($rows)]];
        }

        if (str_contains($normalized, 'FROM users WHERE email')) {
            return array_values(array_filter(
                $this->store['users'],
                fn (array $row): bool => ($row['email'] ?? null) === ($params['email'] ?? null)
            ));
        }

        if (str_contains($normalized, 'FROM users WHERE id')) {
            return array_values(array_filter(
                $this->store['users'],
                fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)
            ));
        }

        if (str_contains($normalized, 'FROM users WHERE api_token_hash IS NOT NULL')) {
            return array_values(array_filter(
                $this->store['users'],
                fn (array $row): bool => !empty($row['api_token_hash'])
            ));
        }

        if (str_contains($normalized, 'FROM users')) {
            $rows = array_filter($this->store['users'], function (array $row) use ($params): bool {
                if (array_key_exists('role', $params) && ($row['role'] ?? null) !== $params['role']) {
                    return false;
                }

                if (array_key_exists('search', $params)) {
                    $needle = strtolower(trim((string) $params['search'], '%'));
                    $haystack = strtolower(($row['name'] ?? '') . ' ' . ($row['email'] ?? ''));

                    if (!str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                return true;
            });

            usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $normalized, $match)) {
                $rows = array_slice($rows, (int) $match[2], (int) $match[1]);
            }

            return array_values($rows);
        }

        if (str_contains($normalized, 'FROM api_tokens')) {
            $rows = array_filter($this->store['api_tokens'], function (array $row) use ($params, $normalized): bool {
                foreach (['id', 'user_id', 'token_lookup'] as $key) {
                    if (array_key_exists($key, $params) && (string) ($row[$key] ?? '') !== (string) $params[$key]) {
                        return false;
                    }
                }

                if (str_contains($normalized, 'revoked_at IS NULL') && !empty($row['revoked_at'])) {
                    return false;
                }

                if (str_contains($normalized, 'token_lookup IS NULL') && !empty($row['token_lookup'])) {
                    return false;
                }

                return true;
            });

            usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            return array_values($rows);
        }

        if (str_contains($normalized, 'COUNT(*) AS total FROM media')) {
            $rows = $this->filterFileMedia($params, $normalized);

            return [['total' => count($rows)]];
        }

        if (str_contains($normalized, 'COUNT(*) AS total FROM term_relationships')) {
            $rows = array_filter(
                $this->store['term_relationships'],
                fn (array $row): bool => (int) ($row['term_id'] ?? 0) === (int) ($params['id'] ?? 0)
            );

            return [['total' => count($rows)]];
        }

        if (str_contains(strtolower($normalized), 'from terms t inner join term_relationships')) {
            $termIds = array_map(
                fn (array $row): int => (int) ($row['term_id'] ?? 0),
                array_filter($this->store['term_relationships'], fn (array $row): bool => (int) ($row['content_id'] ?? 0) === (int) ($params['id'] ?? 0))
            );
            $rows = array_filter($this->store['terms'], function (array $row) use ($params, $termIds): bool {
                if (!in_array((int) ($row['id'] ?? 0), $termIds, true)) {
                    return false;
                }

                return !isset($params['taxonomy']) || ($row['taxonomy'] ?? null) === $params['taxonomy'];
            });

            usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_values($rows);
        }

        if (str_contains($normalized, 'FROM terms')) {
            $rows = array_filter($this->store['terms'], function (array $row) use ($params): bool {
                if (isset($params['taxonomy']) && ($row['taxonomy'] ?? null) !== $params['taxonomy']) {
                    return false;
                }

                if (isset($params['value'])) {
                    return (string) ($row['id'] ?? '') === (string) $params['value']
                        || (string) ($row['slug'] ?? '') === (string) $params['value'];
                }

                if (isset($params['slug']) && (string) ($row['slug'] ?? '') !== (string) $params['slug']) {
                    return false;
                }

                if (isset($params['id']) && str_contains($normalized, 'id != :id') && (int) ($row['id'] ?? 0) === (int) $params['id']) {
                    return false;
                }

                return true;
            });

            usort($rows, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

            return array_values($rows);
        }

        if (str_contains($normalized, 'FROM media')) {
            $rows = $this->filterFileMedia($params, $normalized);

            usort($rows, fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

            if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $normalized, $match)) {
                $rows = array_slice($rows, (int) $match[2], (int) $match[1]);
            }

            return array_values($rows);
        }

        if (str_contains($normalized, 'COUNT(*) AS total FROM content')) {
            $rows = array_filter(
                $this->store['content'],
                fn (array $row): bool => ($row['type'] ?? null) === ($params['type'] ?? null)
            );

            return [['total' => count($rows)]];
        }

        if (str_contains($normalized, 'FROM content_meta')) {
            return array_values(array_filter(
                $this->store['content_meta'],
                fn (array $row): bool => (int) ($row['content_id'] ?? 0) === (int) ($params['id'] ?? 0)
            ));
        }

        if (str_contains($normalized, 'FROM content')) {
            $rows = array_filter($this->store['content'], function (array $row) use ($params, $normalized): bool {
                if (($row['type'] ?? null) !== ($params['type'] ?? null)) {
                    return false;
                }

                foreach (['id', 'slug', 'status'] as $key) {
                    if ($key === 'id' && str_contains($normalized, 'id != :id')) {
                        continue;
                    }

                    if (array_key_exists($key, $params) && (string) ($row[$key] ?? '') !== (string) $params[$key]) {
                        return false;
                    }
                }

                if (str_contains($normalized, "status = 'published'") && ($row['status'] ?? null) !== 'published') {
                    return false;
                }

                if (isset($params['search'])) {
                    $needle = strtolower(trim((string) $params['search'], '%'));
                    $haystack = strtolower(($row['title'] ?? '') . ' ' . ($row['body'] ?? ''));

                    if (!str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                if (str_contains($normalized, 'id != :id') && (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                    return false;
                }

                return true;
            });

            usort($rows, function (array $a, array $b) use ($normalized): int {
                $direction = str_contains($normalized, 'ORDER BY created_at ASC') ? 1 : -1;

                return $direction * strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
            });

            if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $normalized, $match)) {
                $rows = array_slice($rows, (int) $match[2], (int) $match[1]);
            }

            return array_values($rows);
        }

        if (preg_match('/^SELECT COUNT\(\*\) AS total FROM ([a-zA-Z_][a-zA-Z0-9_]*)(?: WHERE (.+))?$/i', $normalized, $match)) {
            $table = $this->identifier($match[1]);
            $rows = $this->store[$table] ?? [];
            $where = $match[2] ?? '';

            if ($where !== '') {
                $rows = array_filter($rows, fn (array $row): bool => $this->matchesWhere($row, $where, $params));
            }

            return [['total' => count($rows)]];
        }

        if (preg_match('/^SELECT \* FROM ([a-zA-Z_][a-zA-Z0-9_]*)(?: WHERE (.*?))?(?: ORDER BY ([a-zA-Z_][a-zA-Z0-9_]*) (ASC|DESC))?(?: LIMIT (\d+) OFFSET (\d+))?$/i', $normalized, $match)) {
            $table = $this->identifier($match[1]);
            $rows = $this->store[$table] ?? [];
            $where = trim((string) ($match[2] ?? ''));

            if ($where !== '') {
                $rows = array_filter($rows, fn (array $row): bool => $this->matchesWhere($row, $where, $params));
            }

            if (!empty($match[3])) {
                $column = $this->identifier($match[3]);
                $direction = strtoupper($match[4] ?? 'ASC') === 'DESC' ? -1 : 1;
                usort($rows, fn (array $a, array $b): int => $direction * strcmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? '')));
            }

            if (isset($match[5], $match[6])) {
                $rows = array_slice($rows, (int) $match[6], (int) $match[5]);
            }

            return array_values($rows);
        }

        return [];
    }

    private function fileExecute(string $sql, array $params): int
    {
        $this->loadFile();
        $normalized = preg_replace('/\s+/', ' ', trim($sql));
        $table = null;
        $filter = fn (array $row): bool => false;

        if (str_starts_with($normalized, 'DELETE FROM options')) {
            $table = 'options';
            $filter = fn (array $row): bool => ($row['option_key'] ?? null) === ($params['key'] ?? null);
        } elseif (str_starts_with($normalized, 'DELETE FROM taxonomies')) {
            $table = 'taxonomies';
            $filter = fn (array $row): bool => ($row['name'] ?? null) === ($params['name'] ?? null);
        } elseif (str_starts_with($normalized, 'DELETE FROM content_meta')) {
            $table = 'content_meta';
            $filter = function (array $row) use ($params): bool {
                if ((int) ($row['content_id'] ?? 0) !== (int) ($params['id'] ?? 0)) {
                    return false;
                }

                return !isset($params['key']) || ($row['meta_key'] ?? null) === $params['key'];
            };
        } elseif (str_starts_with($normalized, 'DELETE FROM term_relationships')) {
            $table = 'term_relationships';
            $filter = function (array $row) use ($params, $normalized): bool {
                if (isset($params['term_id'])) {
                    return (int) ($row['content_id'] ?? 0) === (int) ($params['id'] ?? 0)
                        && (int) ($row['term_id'] ?? 0) === (int) $params['term_id'];
                }

                if (isset($params['id']) && str_contains($normalized, 'term_id = :id')) {
                    return (int) ($row['term_id'] ?? 0) === (int) $params['id'];
                }

                return (int) ($row['content_id'] ?? 0) === (int) ($params['id'] ?? 0);
            };
        } elseif (str_starts_with($normalized, 'DELETE FROM terms')) {
            $table = 'terms';
            $filter = fn (array $row): bool =>
                (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)
                && ($row['taxonomy'] ?? null) === ($params['taxonomy'] ?? null);
        } elseif (str_starts_with($normalized, 'DELETE FROM content')) {
            $table = 'content';
            $filter = fn (array $row): bool =>
                (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)
                && ($row['type'] ?? null) === ($params['type'] ?? null);
        } elseif (str_starts_with($normalized, 'DELETE FROM api_tokens')) {
            $table = 'api_tokens';
            $filter = function (array $row) use ($params): bool {
                if (isset($params['user_id'])) {
                    return (int) ($row['user_id'] ?? 0) === (int) $params['user_id'];
                }

                return (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0);
            };
        } elseif (str_starts_with($normalized, 'DELETE FROM media')) {
            $table = 'media';
            $filter = fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0);
        } elseif (str_starts_with($normalized, 'DELETE FROM users')) {
            $table = 'users';
            $filter = fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0);
        } elseif (preg_match('/^DELETE FROM ([a-zA-Z_][a-zA-Z0-9_]*)(?: WHERE (.+))?$/i', $normalized, $match)) {
            $table = $this->identifier($match[1]);
            $where = trim((string) ($match[2] ?? ''));
            $filter = $where === ''
                ? fn (): bool => true
                : fn (array $row): bool => $this->matchesWhere($row, $where, $params);
        }

        if (!$table) {
            return 0;
        }

        $before = count($this->store[$table]);
        $this->store[$table] = array_values(array_filter($this->store[$table], fn (array $row): bool => !$filter($row)));
        $deleted = $before - count($this->store[$table]);
        $this->saveFile();

        return $deleted;
    }

    private function matchesWhere(array $row, string $where, array $params): bool
    {
        foreach (explode('AND', $where) as $condition) {
            $condition = trim($condition);

            if (!preg_match('/^([a-z_]+)\s*=\s*:([a-z_]+)$/i', $condition, $match)) {
                continue;
            }

            if ((string) ($row[$match[1]] ?? '') !== (string) ($params[$match[2]] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function filterFileMedia(array $params, string $normalized): array
    {
        return array_values(array_filter($this->store['media'], function (array $row) use ($params, $normalized): bool {
            if (array_key_exists('id', $params) && (int) ($row['id'] ?? 0) !== (int) $params['id']) {
                return false;
            }

            if (array_key_exists('mime', $params)) {
                $prefix = rtrim((string) $params['mime'], '%');

                if (!str_starts_with((string) ($row['mime'] ?? ''), $prefix)) {
                    return false;
                }
            }

            foreach (['image', 'audio', 'video'] as $kind) {
                if (str_contains($normalized, "mime NOT LIKE '{$kind}/%'") && str_starts_with((string) ($row['mime'] ?? ''), $kind . '/')) {
                    return false;
                }
            }

            if (array_key_exists('search', $params)) {
                $needle = strtolower(trim((string) $params['search'], '%'));
                $haystack = strtolower(($row['title'] ?? '') . ' ' . ($row['original_name'] ?? '') . ' ' . ($row['alt'] ?? '') . ' ' . ($row['caption'] ?? ''));

                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function loadFile(): void
    {
        if ($this->store) {
            return;
        }

        if (!is_file($this->filePath)) {
            $this->store = [];

            return;
        }

        $decoded = json_decode((string) file_get_contents($this->filePath), true);
        $this->store = is_array($decoded) ? $decoded : [];
    }

    private function saveFile(): void
    {
        file_put_contents($this->filePath, json_encode($this->store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function nextId(string $table): int
    {
        $this->store['_ids'][$table] ??= 1;
        $id = (int) $this->store['_ids'][$table];
        $this->store['_ids'][$table] = $id + 1;

        return $id;
    }
}
