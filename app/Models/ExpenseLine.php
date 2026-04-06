<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseLine extends Model
{
    use BelongsToOrganisation;

    public const SOURCE_SPEND = 'spend';

    public const SOURCE_BILL = 'bill';

    protected $fillable = [
        'organisation_id',
        'source_kind',
        'parent_transaction_id',
        'line_item_id',
        'occurred_on',
        'line_amount',
        'currency_code',
        'account_id',
        'account_code',
        'description',
        'parent_reference',
        'parent_contact_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'line_amount' => 'decimal:4',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
