<?php

declare(strict_types=1);

namespace Kivopress;

final class Scheduler
{
    private const OPTION = 'kivopress_cron_runs';
    private array $events = [];

    public function __construct(private App $app)
    {
    }

    public function schedule(string $hook, int $intervalSeconds, ?callable $callback = null, array $args = []): void
    {
        if ($intervalSeconds < 60) {
            throw new \InvalidArgumentException('Scheduled events must run at least 60 seconds apart.');
        }

        $this->events[$hook] = [
            'hook' => $hook,
            'interval' => $intervalSeconds,
            'callback' => $callback,
            'args' => $args,
        ];
    }

    public function runDue(bool $force = false): array
    {
        $runs = $this->runs();
        $now = time();
        $ran = [];

        foreach ($this->events as $event) {
            $last = (int) ($runs[$event['hook']] ?? 0);

            if (!$force && $last > 0 && ($last + $event['interval']) > $now) {
                continue;
            }

            $this->app->hooks()->doAction($event['hook'], ...$event['args']);

            if (is_callable($event['callback'])) {
                ($event['callback'])(...$event['args']);
            }

            $runs[$event['hook']] = $now;
            $ran[] = $event['hook'];
        }

        if ($ran) {
            $this->app->db()->setOption(self::OPTION, $runs, false);
        }

        return $ran;
    }

    public function events(): array
    {
        return array_map(fn (array $event): array => [
            'hook' => $event['hook'],
            'interval' => $event['interval'],
            'args' => $event['args'],
        ], $this->events);
    }

    private function runs(): array
    {
        $runs = $this->app->db()->getOption(self::OPTION, []);

        return is_array($runs) ? $runs : [];
    }
}
