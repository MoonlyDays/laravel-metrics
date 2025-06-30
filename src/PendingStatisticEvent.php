<?php

namespace MoonlyDays\LaravelMetrics;

use Carbon\CarbonInterface;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

class PendingStatisticEvent
{
    protected string $name = '';

    protected int $value = 0;

    protected ?string $uniqueKey = null;

    protected CarbonInterface $occurredAt;

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

    public function uniqueKey(string $groupBy): static
    {
        $this->uniqueKey = $groupBy;

        return $this;
    }

    public function save(): bool
    {
        $event = new StatisticEvent;
        $event->metric_type = $this->name;
        $event->value = $this->value;
        $event->unique_key = $this->uniqueKey;
        $event->occurred_at = $this->occurredAt;

        return $event->save();
    }

    public function __destruct()
    {
        $this->save();
    }
}
