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

    /**
     * A queued or running run untouched for this long belongs to a worker that died or never started;
     * kept above the sync job's timeout so live runs are never expired.
     */
    public const STALE_AFTER_MINUTES = 20;

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

    /**
     * Mark abandoned queued/running runs as failed so they stop blocking new syncs for the organisation.
     */
    public static function failStaleRuns(int $organisationId): void
    {
        static::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'status' => self::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => __('Sync did not finish. Please try again.'),
            ]);
    }
}
