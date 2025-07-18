<?php

namespace MoonlyDays\LaravelMetrics;

use Carbon\CarbonInterface;
use Illuminate\Support\Traits\Conditionable;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

class PendingStatisticEvent
{
    use Conditionable;

    protected string $name = '';

    protected int $value = 0;

    protected array $parameters = [];

    protected CarbonInterface $occurredAt;

    protected bool $savePerformed = false;

    public function __construct()
    {
        $this->occurredAt = now();
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function value(int $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function occurredAt(CarbonInterface $value): static
    {
        $this->occurredAt = $value;

        return $this;
    }

    public function with(mixed $key, mixed $value = null): static
    {
        if (func_num_args() == 1) {
            $this->parameters = $key;

            return $this;
        }

        return $this->set($key, $value);
    }

    public function set(string $key, mixed $value): static
    {
        $this->parameters[$key] = $value;

        return $this;
    }

    public function save(): bool
    {
        $event = new StatisticEvent;
        $event->metric_type = $this->name;
        $event->value = $this->value;
        $event->occurred_at = $this->occurredAt;
        $event->parameters = $this->parameters;

        $this->savePerformed = true;

        return $event->save();
    }

    public function __destruct()
    {
        if ($this->savePerformed) {
            return;
        }

        $this->save();
    }
}
