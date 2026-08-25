<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class FlightProvider extends Model
{
    use LogsActivity;

    protected $fillable = [
        'code', 'name', 'driver_class', 'base_url', 'environment',
        'credentials', 'is_enabled', 'priority', 'timeout',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest, same pattern as User's 2FA secret — this
            // is the only place a provider's API token/secret is stored.
            'credentials' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'timeout' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['credentials'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('flight_provider');
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
