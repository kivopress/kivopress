<?php

declare(strict_types=1);

namespace Kivopress;

final class Hooks
{
    private array $actions = [];
    private array $filters = [];
    private array $current = [];
    private array $done = [];

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = PHP_INT_MAX): void
    {
        $this->actions[$hook][$priority][] = $this->callback($callback, $acceptedArgs);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->done[$hook] = ($this->done[$hook] ?? 0) + 1;
        $this->current[] = $hook;

        try {
            foreach ($this->callbacks($this->actions[$hook] ?? []) as $registered) {
                $this->run($registered, $args);
            }
        } finally {
            array_pop($this->current);
        }
    }

    public function removeAction(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->actions, $hook, $callback, $priority);
    }

    public function hasAction(string $hook, ?callable $callback = null): bool|int
    {
        return $this->has($this->actions, $hook, $callback);
    }

    public function didAction(string $hook): int
    {
        return (int) ($this->done[$hook] ?? 0);
    }

    public function doingAction(?string $hook = null): bool
    {
        return $hook === null ? $this->current !== [] : in_array($hook, $this->current, true);
    }

    public function currentAction(): ?string
    {
        return $this->current[count($this->current) - 1] ?? null;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = PHP_INT_MAX): void
    {
        $this->filters[$hook][$priority][] = $this->callback($callback, $acceptedArgs);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $this->current[] = $hook;

        try {
            foreach ($this->callbacks($this->filters[$hook] ?? []) as $registered) {
                $value = $this->run($registered, [$value, ...$args]);
            }

            return $value;
        } finally {
            array_pop($this->current);
        }
    }

    public function removeFilter(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->filters, $hook, $callback, $priority);
    }

    public function hasFilter(string $hook, ?callable $callback = null): bool|int
    {
        return $this->has($this->filters, $hook, $callback);
    }

    public function doingFilter(?string $hook = null): bool
    {
        return $this->doingAction($hook);
    }

    public function currentFilter(): ?string
    {
        return $this->currentAction();
    }

    private function callback(callable $callback, int $acceptedArgs): array
    {
        return [
            'callback' => $callback,
            'accepted_args' => max(0, $acceptedArgs),
        ];
    }

    private function callbacks(array $grouped): array
    {
        ksort($grouped);

        return array_merge(...array_values($grouped ?: [[]]));
    }

    private function run(array $registered, array $args): mixed
    {
        $accepted = (int) $registered['accepted_args'];
        $callback = $registered['callback'];

        return $callback(...($accepted === PHP_INT_MAX ? $args : array_slice($args, 0, $accepted)));
    }

    private function remove(array &$registry, string $hook, callable $callback, int $priority): bool
    {
        $removed = false;

        foreach ($registry[$hook][$priority] ?? [] as $index => $registered) {
            if ($registered['callback'] === $callback) {
                unset($registry[$hook][$priority][$index]);
                $removed = true;
            }
        }

        if ($removed) {
            $registry[$hook][$priority] = array_values($registry[$hook][$priority]);
        }

        return $removed;
    }

    private function has(array $registry, string $hook, ?callable $callback): bool|int
    {
        if (!isset($registry[$hook])) {
            return false;
        }

        if ($callback === null) {
            foreach ($registry[$hook] as $callbacks) {
                if ($callbacks !== []) {
                    return true;
                }
            }

            return false;
        }

        foreach ($registry[$hook] as $priority => $callbacks) {
            foreach ($callbacks as $registered) {
                if ($registered['callback'] === $callback) {
                    return (int) $priority;
                }
            }
        }

        return false;
    }
}
