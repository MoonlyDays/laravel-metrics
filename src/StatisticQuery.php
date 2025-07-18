<?php

namespace MoonlyDays\LaravelMetrics;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MoonlyDays\LaravelMetrics\Exceptions\LaravelMetricsException;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

class StatisticQuery
{
    protected string $name;

    protected ?string $period = 'day';

    protected string $aggregate = 'sum';

    protected ?string $uniqueBy = null;

    protected Builder $builder;

    protected CarbonInterface $start;

    protected CarbonInterface $end;

    public function __construct()
    {
        $this->builder = StatisticEvent::query();
        $this->start = now()->subMonth();
        $this->end = now();
    }

    /**
     * @throws LaravelMetricsException
     */
    public function sum(): array
    {
        return $this->aggregate('sum')->get();
    }

    /**
     * @throws LaravelMetricsException
     */
    public function count(): array
    {
        return $this->aggregate('count')->get();
    }

    /**
     * @throws LaravelMetricsException
     */
    public function avg(): array
    {
        return $this->aggregate('avg')->get();
    }

    public function where(callable $closure): static
    {
        return $this;
    }

    /**
     * @throws LaravelMetricsException
     */
    protected function periods(): Collection
    {
        $data = collect();
        $currentDateTime = (new Carbon($this->start))->startOf($this->period);

        do {
            $data->push($currentDateTime->format($this->getPeriodTimestampFormat()));

            $currentDateTime->add(1, $this->period);
        } while ($currentDateTime->lt($this->end));

        return $data;
    }

    /**
     * @throws LaravelMetricsException
     */
    public function get(): array
    {
        $query = StatisticEvent::query()
            ->where('metric_type', $this->name)
            ->when(
                ! empty($this->whereConstraints),
                fn ($builder) => $builder->whereJsonContains('parameters', $this->whereConstraints)
            )
            ->whereBetween('occurred_at', [$this->start, $this->end])
            ->select(DB::raw($this->getAggregateSqlExpression('value').' as value'));

        if (! is_null($this->period)) {
            $dataPoints = $query->groupBy('period')
                ->addSelect(DB::raw($this->getPeriodSqlExpression('occurred_at').' as period'))
                ->pluck('value', 'period');

            $dataPoints = $this->periods()->map(fn (string $period) => [
                'period' => $period,
                'value' => intval($dataPoints->get($period, 0)),
            ])->toArray();
        } else {
            $dataPoints = [
                'period' => $this->start->toDateString().' - '.$this->end->toDateString(),
                'value' => intval($query->get()->first()['value']),
            ];
        }

        return $dataPoints;
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function start(CarbonInterface $value): static
    {
        $this->start = $value;

        return $this;
    }

    public function end(CarbonInterface $value): static
    {
        $this->end = $value;

        return $this;
    }

    public function groupBy(?string $period): static
    {
        $this->period = $period;

        return $this;
    }

    public function uniqueBy(?string $key): static
    {
        $this->uniqueBy = $key;

        return $this;
    }

    public function aggregate(string $func): static
    {
        $this->aggregate = Str::lower($func);

        return $this;
    }

    public function groupByYear(): static
    {
        return $this->groupBy('year');
    }

    public function groupByWeek(): static
    {
        return $this->groupBy('week');
    }

    public function groupByMonth(): static
    {
        return $this->groupBy('month');
    }

    public function groupByDay(): static
    {
        return $this->groupBy('day');
    }

    public function groupByHour(): static
    {
        return $this->groupBy('hour');
    }

    public function groupByMinute(): static
    {
        return $this->groupBy('minute');
    }

    /**
     * @throws LaravelMetricsException
     */
    protected function getAggregateSqlExpression(string $valueColumn): string
    {
        if ($this->uniqueBy && $this->aggregate !== 'count') {
            throw new LaravelMetricsException($this->aggregate.' aggregate is unavailable when unique by constraint is set.');
        }

        $column = match ($this->aggregate) {
            'count' => $this->uniqueBy
                ? 'DISTINCT '.$this->getExtractUniqueKeySqlExpression('parameters', $this->uniqueBy)
                : $valueColumn,
            default => $valueColumn,
        };

        return Str::upper($this->aggregate).'('.$column.')';
    }

    protected function getExtractUniqueKeySqlExpression(string $parametersColumn, string $uniqueKey): string
    {
        return "JSON_EXTRACT(`$parametersColumn`, \"$.$uniqueKey\")";
    }

    /**
     * @throws LaravelMetricsException
     */
    protected function getPeriodSqlExpression(string $column): string
    {
        if (is_null($this->period)) {
            throw new LaravelMetricsException('getPeriodSqlExpression called with null period.');
        }

        $dbDriver = app(StatisticEvent::class)->getConnection()->getDriverName();

        if ($dbDriver === 'pgsql') {
            return match ($this->period) {
                'year' => "to_char($column, 'YYYY')",
                'month' => "to_char($column, 'YYYY-MM')",
                'week' => "to_char($column, 'IYYYIW')",
                'day' => "to_char($column, 'YYYY-MM-DD')",
                'hour' => "to_char($column, 'YYYY-MM-DD HH24')",
                'minute' => "to_char($column, 'YYYY-MM-DD HH24:MI')",
            };
        }

        if ($dbDriver === 'sqlite') {
            return match ($this->period) {
                'year' => "strftime('%Y', $column)",
                'month' => "strftime('%Y-%m', $column)",
                'week' => "strftime('%Y%W', $column)",
                'day' => "strftime('%Y-%m-%d', $column)",
                'hour' => "strftime('%Y-%m-%d %H', $column)",
                'minute' => "strftime('%Y-%m-%d %H:%M', $column)",
            };
        }

        return match ($this->period) {
            'year' => "date_format($column,'%Y')",
            'month' => "date_format($column,'%Y-%m')",
            'week' => 'yearweek($column, 3)',
            'day' => "date_format($column,'%Y-%m-%d')",
            'hour' => "date_format($column,'%Y-%m-%d %H')",
            'minute' => "date_format($column,'%Y-%m-%d %H:%i')",
        };
    }

    /**
     * @throws LaravelMetricsException
     */
    protected function getPeriodTimestampFormat(): string
    {
        if (is_null($this->period)) {
            throw new LaravelMetricsException('getPeriodTimestampFormat called with null period.');
        }

        return match ($this->period) {
            'year' => 'Y',
            'month' => 'Y-m',
            'week' => 'oW', // see https://stackoverflow.com/questions/15562270/php-datew-vs-mysql-yearweeknow
            'day' => 'Y-m-d',
            'hour' => 'Y-m-d H',
            'minute' => 'Y-m-d H:i',
        };
    }
}
