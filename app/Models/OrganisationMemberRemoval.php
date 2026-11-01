<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
