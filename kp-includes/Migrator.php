<?php

declare(strict_types=1);

namespace Kivopress;

final class Migrator
{
    private const OPTION = 'kivopress_migrations';
    private array $migrations = [];

    public function __construct(private Database $db)
    {
    }

    public function register(string $version, callable $callback, string $description = ''): void
    {
        if (!preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$/', $version)) {
            throw new \InvalidArgumentException('Migration versions must use yyyy_mm_dd_hhmmss_name format.');
        }

        $this->migrations[$version] = [
            'version' => $version,
            'description' => $description,
            'callback' => $callback,
        ];

        ksort($this->migrations);
    }

    public function run(): array
    {
        $applied = $this->applied();
        $ran = [];

        foreach ($this->migrations as $version => $migration) {
            if (isset($applied[$version])) {
                continue;
            }

            ($migration['callback'])($this->db);
            $applied[$version] = [
                'description' => $migration['description'],
                'ran_at' => $this->db->now(),
            ];
            $ran[] = $version;
            $this->db->setOption(self::OPTION, $applied, false);
        }

        return $ran;
    }

    public function applied(): array
    {
        $applied = $this->db->getOption(self::OPTION, []);

        return is_array($applied) ? $applied : [];
    }

    public function migrations(): array
    {
        return $this->migrations;
    }
}
