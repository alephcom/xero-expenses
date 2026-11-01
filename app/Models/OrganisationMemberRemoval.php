<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records that an admin removed a user from an organisation, so Xero SSO reviewer
 * auto-attach does not silently add them back. Cleared when the user accepts a new invitation.
 */
class OrganisationMemberRemoval extends Model
{
    protected $fillable = [
        'organisation_id',
        'user_id',
        'removed_by',
    ];

    /**
     * Run $callback while holding the lock that removal and Xero SSO auto-attach share for one
     * user and organisation, so an attach can't slip between a removal's two writes.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withMembershipLock(Organisation $organisation, int $userId, Closure $callback): mixed
    {
        return Cache::lock('organisation-membership:'.$organisation->id.':'.$userId, 30)
            ->block(10, $callback);
    }

    /**
     * Record the removal before detaching, in one transaction, under the membership lock.
     */
    public static function removeMember(Organisation $organisation, int $userId, ?int $removedBy): void
    {
        static::withMembershipLock($organisation, $userId, function () use ($organisation, $userId, $removedBy): void {
            DB::transaction(function () use ($organisation, $userId, $removedBy): void {
                static::record($organisation, $userId, $removedBy);
                $organisation->users()->detach($userId);
            });
        });
    }

    public static function exists(Organisation $organisation, int $userId): bool
    {
        return static::query()
            ->where('organisation_id', $organisation->id)
            ->where('user_id', $userId)
            ->exists();
    }

    public static function record(Organisation $organisation, int $userId, ?int $removedBy): void
    {
        static::query()->updateOrCreate(
            ['organisation_id' => $organisation->id, 'user_id' => $userId],
            ['removed_by' => $removedBy],
        );
    }

    public static function clear(Organisation $organisation, int $userId): void
    {
        static::query()
            ->where('organisation_id', $organisation->id)
            ->where('user_id', $userId)
            ->delete();
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
