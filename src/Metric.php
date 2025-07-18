<?php

namespace MoonlyDays\LaravelMetrics;

abstract class Metric
{
    public static function name(): string
    {
        return basename(static::class);
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

    public static function query(): StatisticQuery
    {
        return static::newQuery();
    }

    protected static function newEvent(): PendingStatisticEvent
    {
        return app(PendingStatisticEvent::class)->name(static::name());
    }

    protected static function newQuery(): StatisticQuery
    {
        return app(StatisticQuery::class)->name(static::name());
    }
}
