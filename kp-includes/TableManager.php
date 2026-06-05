<?php

declare(strict_types=1);

namespace Kivopress;

final class TableManager
{
    private const VERSION_OPTION = 'kivopress_custom_table_versions';

    public function __construct(private Database $db)
    {
    }

    public function name(string $name): string
    {
        $name = $this->identifier($name);
        $prefix = $this->db->prefix();

        return str_starts_with($name, $prefix) ? $name : $prefix . $name;
    }

    public function create(string $name, array $schema): string
    {
        $table = $this->name($name);
        $schema = $this->normalizeSchema($schema);
        $versions = $this->versions();
        $previousVersion = $versions[$table] ?? null;
        $created = !$this->db->tableExists($table);

        if ($this->db->fileMode()) {
            $this->db->ensureFileTable($table, $this->hasAutoIncrementId($schema));
        } else {
            $this->createSqlTable($table, $schema);
            $this->addMissingColumns($table, $schema);
            $this->createIndexes($table, $schema);
        }

        if (is_callable($schema['seed'] ?? null) && ($created || $previousVersion === null)) {
            $schema['seed']($table, $this->db);
        }

        $version = (string) ($schema['version'] ?? '1.0.0');
        $versions[$table] = $version;
        $this->db->setOption(self::VERSION_OPTION, $versions, false);

        do_action('database.table_created', $table, $schema, $created, $previousVersion);

        return $table;
    }

    public function versions(): array
    {
        $versions = $this->db->getOption(self::VERSION_OPTION, []);

        return is_array($versions) ? $versions : [];
    }

    private function createSqlTable(string $table, array $schema): void
    {
        $lines = [];

        foreach ($schema['columns'] as $column => $definition) {
            $lines[] = $this->columnSql($column, $definition, true);
        }

        if (!empty($schema['primary']) && !($this->hasAutoIncrementId($schema) && !$this->db->isMysql())) {
            $lines[] = 'PRIMARY KEY  (' . $this->columnList($schema['primary']) . ')';
        }

        $charset = $this->db->isMysql() ? ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' : '';
        $this->db->pdo()->exec("CREATE TABLE IF NOT EXISTS {$table} (\n  " . implode(",\n  ", $lines) . "\n){$charset}");
    }

    private function addMissingColumns(string $table, array $schema): void
    {
        foreach ($schema['columns'] as $column => $definition) {
            if ($this->db->hasColumn($table, $column)) {
                continue;
            }

            $this->db->addColumn($table, $this->columnSql($column, $definition, false));
        }
    }

    private function createIndexes(string $table, array $schema): void
    {
        foreach ($schema['keys'] as $name => $columns) {
            $this->createIndex($table, (string) $name, (array) $columns, false);
        }

        foreach ($schema['unique'] as $name => $columns) {
            $this->createIndex($table, (string) $name, (array) $columns, true);
        }
    }

    private function createIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $name = $this->identifier($table . '_' . $name);
        $columns = $this->columnList($columns);
        $prefix = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
        $ifNotExists = $this->db->isMysql() ? '' : ' IF NOT EXISTS';

        try {
            $this->db->pdo()->exec("{$prefix}{$ifNotExists} {$name} ON {$table} ({$columns})");
        } catch (\Throwable) {
            // Existing indexes are fine; table creation must be idempotent.
        }
    }

    private function columnSql(string $column, array $definition, bool $create): string
    {
        $column = $this->identifier($column);
        $type = (string) ($definition['type'] ?? 'string');

        if ($type === 'id' && !$this->db->isMysql()) {
            return $column . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $column . ' ' . $this->typeSql($definition);

        if (!($definition['null'] ?? false)) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $definition)) {
            $sql .= ' DEFAULT ' . $this->defaultSql($definition['default']);
        }

        if ($create && $type === 'id' && $this->db->isMysql()) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }

    private function typeSql(array $definition): string
    {
        $type = (string) ($definition['type'] ?? 'string');
        $length = (int) ($definition['length'] ?? 0);

        return match ($type) {
            'id' => $this->db->isMysql() ? 'BIGINT UNSIGNED' : 'INTEGER',
            'bigint' => $this->db->isMysql() ? 'BIGINT' : 'INTEGER',
            'integer' => $this->db->isMysql() ? 'INT' : 'INTEGER',
            'boolean' => $this->db->isMysql() ? 'TINYINT(1)' : 'INTEGER',
            'decimal' => 'DECIMAL(' . ($definition['precision'] ?? 10) . ',' . ($definition['scale'] ?? 2) . ')',
            'datetime' => $this->db->isMysql() ? 'DATETIME' : 'TEXT',
            'text' => 'TEXT',
            'longtext', 'json' => $this->db->isMysql() ? 'LONGTEXT' : 'TEXT',
            default => 'VARCHAR(' . ($length > 0 ? $length : 191) . ')',
        };
    }

    private function normalizeSchema(array $schema): array
    {
        if (empty($schema['columns']) || !is_array($schema['columns'])) {
            throw new \InvalidArgumentException('Custom tables require a columns array.');
        }

        foreach ($schema['columns'] as $column => &$definition) {
            $this->identifier((string) $column);
            $definition = is_array($definition) ? $definition : ['type' => (string) $definition];
        }

        unset($definition);

        if (empty($schema['primary']) && isset($schema['columns']['id'])) {
            $schema['primary'] = ['id'];
        }

        return array_merge([
            'version' => '1.0.0',
            'primary' => [],
            'keys' => [],
            'unique' => [],
            'seed' => null,
        ], $schema);
    }

    private function hasAutoIncrementId(array $schema): bool
    {
        return (string) ($schema['columns']['id']['type'] ?? '') === 'id';
    }

    private function columnList(array $columns): string
    {
        return implode(', ', array_map(fn (string $column): string => $this->identifier($column), $columns));
    }

    private function defaultSql(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->db->pdo()->quote((string) $value);
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $value)) {
            throw new \InvalidArgumentException("Invalid table identifier [{$value}].");
        }

        return $value;
    }
}
