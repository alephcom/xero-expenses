<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Records that an admin removed a user from an organisation, so automatic Xero SSO access does not
 * re-add them. Cleared when the user accepts a new invitation to that organisation.
 */
class OrganisationAccessExclusion extends Model
{
    protected $fillable = [
        'organisation_id',
        'user_id',
        'excluded_by',
    ];

    /**
     * Run $callback while holding the lock that admin removal, invitation acceptance and Xero SSO
     * reviewer sync share for one user and organisation, so their reads and writes can't interleave.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withMembershipLock(int $organisationId, int $userId, Closure $callback): mixed
    {
        return Cache::lock('organisation-membership:'.$organisationId.':'.$userId, 30)
            ->block(10, $callback);
    }

    public static function existsFor(int $organisationId, int $userId): bool
    {
        return static::query()
            ->where('organisation_id', $organisationId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
