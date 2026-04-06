<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineReview extends Model
{
    use BelongsToOrganisation;

    protected $fillable = [
        'organisation_id',
        'source_kind',
        'parent_transaction_id',
        'line_item_id',
        'is_reviewed',
        'needs_invoicing',
        'selected_contact_id',
        'notes',
        'user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_reviewed' => 'boolean',
            'needs_invoicing' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
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
