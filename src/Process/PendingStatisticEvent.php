<?php

namespace MoonlyDays\LaravelMetrics\Process;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;
use MoonlyDays\LaravelMetrics\Exceptions\LaravelMetricsException;
use MoonlyDays\LaravelMetrics\Metric;
use MoonlyDays\LaravelMetrics\Models\StatisticEvent;

class PendingStatisticEvent
{
    use Conditionable;
    use Tappable;

    protected Metric $metric;

    protected int $value = 0;

    protected array $parameters = [];

    protected CarbonInterface $occurredAt;

    protected bool $saved = false;

    public function __construct()
    {
        $this->occurredAt = now();
    }

    public function metric(Metric $metric): self
    {
        $this->metric = $metric;

        return $this;
    }

    public function value(int $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function occurredAt(CarbonInterface $value): self
    {
        $this->occurredAt = $value;

        return $this;
    }

    public function with(string|array $parameters, $value = null): self
    {
        if (is_string($parameters)) {
            $this->parameters[$parameters] = $value;

            return $this;
        }

        $totallyGuarded = $this->metric->totallyGuarded();
        $fillable = $this->metric->fillableFromArray($parameters);

        foreach ($fillable as $key => $value) {
            if ($this->metric->isFillable($key)) {
                $this->with($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException(sprintf(
                    'Add [%s] to fillable property to allow mass assignment on [%s].',
                    $key, get_class($this)
                ));
            }
        }

        return $this;
    }

    /**
     * Saves the current statistic event and its associated parameters.
     *
     * @throws LaravelMetricsException
     */
    public function save(): void
    {
        $event = new StatisticEvent;
        $event->metric_type = $this->metric->name();
        $event->value = $this->value;
        $event->occurred_at = $this->occurredAt;
        $event->parameters = $this->parameters;

        $this->saved = true;

        if (! $event->save()) {
            throw new LaravelMetricsException('Failed to save statistic event.');
        }
    }

    /**
     * @throws LaravelMetricsException
     */
    public function __destruct()
    {
        if ($this->saved) {
            return;
        }

        $this->save();
    }
}
