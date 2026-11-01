<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Organisation extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'public_id',
        'xero_tenant_id',
        'xero_short_code',
        'oauth_batch_id',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Organisation $organisation): void {
            if ($organisation->public_id === null || $organisation->public_id === '') {
                $organisation->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Persist a route key for legacy rows that pre-date public_id backfills so URL generation never fails.
     */
    public function getRouteKey(): mixed
    {
        if ($this->public_id === null || $this->public_id === '') {
            $this->public_id = (string) Str::ulid();
            if ($this->exists) {
                $this->saveQuietly();
            }
        }

        return $this->public_id;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Unscoped: the relation is already constrained to this organisation, and token refresh
     * must reach peer organisations' connections even while OrganisationContext is set.
     */
    public function xeroConnection(): HasOne
    {
        return $this->hasOne(XeroConnection::class)->withoutGlobalScopes();
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function linkedTransactionLines(): HasMany
    {
        return $this->hasMany(LinkedTransactionLine::class);
    }

    public function lineReviews(): HasMany
    {
        return $this->hasMany(LineReview::class);
    }
}
