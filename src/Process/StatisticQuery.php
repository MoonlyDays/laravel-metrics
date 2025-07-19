<?php

namespace MoonlyDays\LaravelMetrics\Process;

use Arr;
use BadMethodCallException;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\Unit;
use DateInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;
use MoonlyDays\LaravelMetrics\Exceptions\LaravelMetricsException;
use MoonlyDays\LaravelMetrics\Metric;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

/**
 * @template T of Metric
 *
 * @mixin T
 */
class StatisticQuery
{
    use Conditionable;
    use Tappable;

    protected Metric $metric;

    protected ?string $period = 'day';

    protected string $aggregate = 'sum';

    protected ?string $uniqueBy = null;

    protected array $whereConstraints = [];

    protected bool $useCache = true;

    protected bool $includeTotal = false;

    protected string $totalPeriod = 'Total';

    protected CarbonInterval $cacheFor;

    protected CarbonInterface $start;

    protected CarbonInterface $end;

    public function __construct()
    {
        $this->start = now()->subMonth();
        $this->end = now();
        $this->cacheFor = CarbonInterval::make('15 minutes');
    }

    public function sum(): array
    {
        return $this->aggregate('sum')->get();
    }

    public function count(): array
    {
        return $this->aggregate('count')->get();
    }

    public function avg(): array
    {
        return $this->aggregate('avg')->get();
    }

    public function min(): array
    {
        return $this->aggregate('min')->get();
    }

    public function max(): array
    {
        return $this->aggregate('max')->get();
    }

    public function metric(Metric $metric): self
    {
        $this->metric = $metric;

        return $this;
    }

    public function start(CarbonInterface $value): self
    {
        $this->start = $value;

        return $this;
    }

    public function end(CarbonInterface $value): self
    {
        $this->end = $value;

        return $this;
    }

    public function groupBy(?string $period): self
    {
        $this->period = $period;

        return $this;
    }

    public function uniqueBy(?string $key): self
    {
        $this->uniqueBy = $key;

        return $this;
    }

    public function aggregate(string $func): self
    {
        $this->aggregate = Str::lower($func);

        return $this;
    }

    public function groupByYear(): self
    {
        return $this->groupBy('year');
    }

    public function groupByWeek(): self
    {
        return $this->groupBy('week');
    }

    public function groupByMonth(): self
    {
        return $this->groupBy('month');
    }

    public function groupByDay(): self
    {
        return $this->groupBy('day');
    }

    public function groupByHour(): self
    {
        return $this->groupBy('hour');
    }

    public function groupByMinute(): self
    {
        return $this->groupBy('minute');
    }

    protected function getAggregateSqlExpression(string $valueColumn): string
    {
        $column = $this->uniqueBy
            ? 'DISTINCT '.$this->getExtractUniqueKeySqlExpression('parameters', $this->uniqueBy)
            : $valueColumn;

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
            'week' => 'oW',
            'day' => 'Y-m-d',
            'hour' => 'Y-m-d H',
            'minute' => 'Y-m-d H:i',
        };
    }

    public function whereNot(string|array $column, $value = null, $boolean = 'and'): self
    {
        return $this->addWhereConstraint($column, $value, $boolean, true);
    }

    public function orWhereNot(string|array $column, $value = null): self
    {
        return $this->whereNot($column, $value, 'or');
    }

    public function where(string|array $column, $value = null, $boolean = 'and'): self
    {
        return $this->addWhereConstraint($column, $value, $boolean, false);
    }

    public function orWhere(string|array $column, $value = null): self
    {
        return $this->where($column, $value, 'or');
    }

    protected function addWhereConstraint(string|array $column, $value, $boolean, bool $not): self
    {
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                $this->addWhereConstraint($key, $value, $boolean, $not);
            }

            return $this;
        }

        $this->whereConstraints[$column] = [
            'column' => $column,
            'value' => $value,
            'boolean' => $boolean,
            'not' => $not,
        ];

        return $this;
    }

    public function whereTrue(string $column): self
    {
        return $this->where($column, true);
    }

    public function whereFalse(string $column): self
    {
        return $this->where($column, false);
    }

    protected function statisticQuery(): Builder
    {
        return StatisticEvent::query()
            ->where('metric_type', $this->metric->name())
            ->whereBetween('occurred_at', [$this->start, $this->end]);
    }

    public function get(): array
    {
        return $this->throughCache($this->getDataPoints(...));
    }

    protected function throughCache(callable $callback): array
    {
        if (! $this->useCache) {
            return $callback();
        }

        return Cache::flexible(
            $this->makeResponseCacheKey(),
            [$this->cacheFor, $this->cacheFor->divide(2)],
            $callback,
        );
    }

    /**
     * @throws LaravelMetricsException
     */
    protected function getDataPoints()
    {
        $query = $this->statisticQuery()
            ->select(DB::raw($this->getAggregateSqlExpression('value').' as value'))
            ->tap(fn (Builder $query) => $query->where(function (Builder $query) {
                foreach ($this->whereConstraints as $constraint) {
                    $query->whereJsonContains(
                        'parameters',
                        [$constraint['column'] => $constraint['value']],
                        $constraint['boolean'],
                        $constraint['not']
                    );
                }
            }));

        if (is_null($this->period)) {
            return [
                'period' => $this->start->toDateString().' - '.$this->end->toDateString(),
                'value' => floatval($query->get()->first()['value']),
            ];
        }

        $dataPoints = $query->groupBy('period')
            ->addSelect(DB::raw($this->getPeriodSqlExpression('occurred_at').' as period'))
            ->pluck('value', 'period');

        $dataPoints = $this->periods()->map(fn (string $period) => [
            'period' => $period,
            'value' => floatval($dataPoints->get($period, 0)),
        ]);

        if ($this->includeTotal) {
            $dataPoints->push([
                'period' => $this->totalPeriod,
                'value' => $this->rollupTotal($dataPoints),
            ]);
        }

        return $dataPoints->toArray();
    }

    public function __call(string $name, array $arguments)
    {
        if ($this->metric->addScope($this, $name, $arguments)) {
            return $this;
        }

        throw new BadMethodCallException;
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

    public function useCache(bool $use = true): self
    {
        $this->useCache = $use;

        return $this;
    }

    public function withoutCache(bool $without = true): self
    {
        return $this->useCache(! $without);
    }

    /**
     * @param  mixed|int|DateInterval|string|Closure|Unit|null  $interval
     */
    public function cacheFor(mixed $interval, Unit|string|null $unit = null): self
    {
        $this->cacheFor = CarbonInterval::make($interval, $unit);

        return $this->useCache();
    }

    protected function makeResponseCacheKey(): string
    {
        return implode(':', [
            'statistics',
            $this->aggregate,
            $this->includeTotal,
            $this->metric->name(),
            $this->uniqueBy,
            $this->start->toDateTimeString(),
            $this->end->toDateTimeString(),
            $this->buildWhereConstraintsString(),
        ]);
    }

    protected function buildWhereConstraintsString(): string
    {
        return implode(Arr::map($this->whereConstraints, fn ($constraint) => implode('', [
            $constraint['column'],
            $constraint['not'] ? '!=' : '=',
            json_encode($constraint['value']),
            $constraint['boolean'] == 'and' ? '&' : '|',
        ])));
    }

    protected function rollupTotal(Collection $dataPoints): float|int
    {
        $values = $dataPoints->pluck('value')->all();

        return match (Str::lower($this->aggregate)) {
            'sum', 'count' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
        };
    }

    public function includeTotal(bool $use = true, string $total = 'Total'): self
    {
        $this->includeTotal = $use;
        $this->totalPeriod = $total;

        return $this;
    }
}
