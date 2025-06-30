<?php

namespace MoonlyDays\LaravelMetrics;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

class PendingStatisticEvent
{
    protected string $name = '';

    protected int $value = 0;

    protected ?string $uniqueKey = null;

    protected ?string $uniquePeriod = null;

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

    public function yearlyUnique(string $unique): static
    {
        return $this->unique($unique, 'year');
    }

    public function weeklyUnique(string $unique): static
    {
        return $this->unique($unique, 'week');
    }

    public function monthlyUnique(string $unique): static
    {
        return $this->unique($unique, 'month');
    }

    public function dailyUnique(string $unique): static
    {
        return $this->unique($unique, 'day');
    }

    public function hourlyUnique(string $unique): static
    {
        return $this->unique($unique, 'hour');
    }

    public function minutelyUnique(string $unique): static
    {
        return $this->unique($unique, 'minute');
    }

    public function unique(string $unique, string $period): static
    {
        $this->uniqueKey = $unique;
        $this->uniquePeriod = $period;

        return $this;
    }

    public function save(): bool
    {
        if (! is_null($existing = $this->existingUnique())) {
            $existing->increment('value', $this->value);

            return true;
        }

        $event = new StatisticEvent;
        $event->metric_type = $this->name;
        $event->value = $this->value;
        $event->unique_key = $this->uniqueKey;
        $event->occurred_at = $this->occurredAt;

        return $event->save();
    }

    public function existingUnique()
    {
        if (is_null($this->uniqueKey)) {
            return null;
        }

        $periodFrom = Carbon::parse($this->occurredAt)->startOf($this->uniquePeriod);
        $periodTo = $periodFrom->copy()->endOf($this->uniquePeriod);

        return StatisticEvent::query()
            ->where('metric_type', $this->name)
            ->where('unique_key', $this->uniqueKey)
            ->whereBetween('occurred_at', [$periodFrom, $periodTo])
            ->first();
    }

    public function __destruct()
    {
        $this->save();
    }
}
