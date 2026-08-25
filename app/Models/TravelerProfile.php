<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TravelerProfile extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id', 'title', 'gender', 'first_name', 'last_name', 'date_of_birth',
        'nationality', 'email', 'phone', 'passport_number', 'passport_expiry',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'passport_expiry' => 'date',
            // Encrypted at rest — same pattern as User's 2FA secret.
            'passport_number' => 'encrypted',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['passport_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('traveler_profile');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
