<?php

namespace MoonlyDays\LaravelMetrics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatisticEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'parameters' => 'array',
        ];
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function for(): MorphTo
    {
        return $this->morphTo();
    }
}
