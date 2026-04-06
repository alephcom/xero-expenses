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
        'xero_linked_transaction_id',
        'source_transaction_id',
        'source_line_item_id',
        'contact_id',
        'target_transaction_id',
        'target_line_item_id',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
