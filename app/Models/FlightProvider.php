<?php

namespace App\Models;

use App\Services\Flights\FlightProviderManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class FlightProvider extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        // Keeps FlightProviderManager's enabled-providers cache (see its
        // CACHE_KEY) from ever serving a stale row after an admin
        // enables/disables a provider or edits its credentials — a 10-minute
        // TTL alone would leave exactly that window of staleness otherwise.
        static::saved(fn () => Cache::forget(FlightProviderManager::CACHE_KEY));
        static::deleted(fn () => Cache::forget(FlightProviderManager::CACHE_KEY));
    }

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
