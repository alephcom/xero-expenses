<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XeroAccount extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id',
        'xero_account_id',
        'code',
        'name',
        'type',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
