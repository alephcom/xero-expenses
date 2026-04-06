<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sync lifecycle: queued → running → completed | failed.
 * UI creates {@see self::STATUS_QUEUED} before dispatching the job; the worker moves to running then terminal state.
 */
class SyncRun extends Model
{
    use BelongsToOrganisation;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organisation_id',
        'status',
        'started_at',
        'finished_at',
        'date_from',
        'date_to',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'date_from' => 'date',
            'date_to' => 'date',
            'meta' => 'array',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
