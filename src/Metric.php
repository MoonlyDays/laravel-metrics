<?php

namespace MoonlyDays\LaravelMetrics;

use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Support\Str;
use MoonlyDays\LaravelMetrics\Process\PendingStatisticEvent;
use MoonlyDays\LaravelMetrics\Process\StatisticQuery;

abstract class Metric
{
    use GuardsAttributes;

    public function name(): string
    {
        return basename(static::class);
    }

    public function addScope(StatisticQuery $event, $scope, $arguments): bool
    {
        if (method_exists($this, $methodName = 'scope'.Str::studly($scope))) {
            $this->{$methodName}($event, ...$arguments);

            return true;
        }

        return false;
    }

    public function onlyFillable(array $attributes)
    {
        return $this->fillableFromArray($attributes);
    }

    public static function add(int $value): PendingStatisticEvent
    {
        return static::newEvent()->value($value);
    }

    public static function sub(int $value): PendingStatisticEvent
    {
        return static::add(-$value);
    }

    public static function increment(): PendingStatisticEvent
    {
        return static::add(1);
    }

    public static function decrement(): PendingStatisticEvent
    {
        return static::sub(1);
    }

    /**
     * @return StatisticQuery|Metric
     */
    public static function query(): StatisticQuery|static
    {
        return static::newQuery();
    }

    protected static function newEvent(): PendingStatisticEvent
    {
        return app(PendingStatisticEvent::class)->metric(app(static::class));
    }

    protected static function newQuery(): StatisticQuery
    {
        return app(StatisticQuery::class)->metric(app(static::class));
    }
}
