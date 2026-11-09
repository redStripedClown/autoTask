<?php

declare(strict_types=1);

namespace AutoTask;

class AutoTask
{
    public function __construct(
        private array $tasks = [],
    ) {
    }

    public function add(string $name, callable $callback): void
    {
        $this->tasks[$name] = $callback;
    }

    public function run(): void
    {
        foreach ($this->tasks as $name => $callback) {
            echo "[run] {$name}" . PHP_EOL;
            $callback();
        }
    }
}