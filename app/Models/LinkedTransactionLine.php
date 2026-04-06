<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedTransactionLine extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id',
        'source_transaction_id',
        'source_line_item_id',
        'contact_id',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
