<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchQuotaUsage extends Model
{
    protected $table = 'search_quota_usage';

    protected $fillable = ['user_id', 'period_type', 'period_key', 'used_count', 'limit_snapshot'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
