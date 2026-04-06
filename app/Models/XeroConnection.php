<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XeroConnection extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'token_type',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
