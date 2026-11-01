<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
