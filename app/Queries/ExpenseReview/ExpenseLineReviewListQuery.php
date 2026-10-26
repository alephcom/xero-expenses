<?php

namespace App\Queries\ExpenseReview;

use App\Models\XeroContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ExpenseLineReviewListQuery
{
    /** @var list<string> */
    public const SORTABLE = [
        'occurred_on',
        'source_kind',
        'parent_reference',
        'account_code',
        'account_name',
        'description',
        'line_amount',
        'parent_contact_name',
        'linked_contact_name',
        'selected_contact_name',
        'notes',
        'is_reviewed',
    ];

    /**
     * Active contacts only; archived contacts stay in the table so existing links keep their names.
     *
     * @return list<array{id: string, name: string}>
     */
    public function contactsForPicker(int $organisationId): array
    {
        return XeroContact::withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['xero_contact_id', 'name'])
            ->map(fn (XeroContact $c) => [
                'id' => (string) $c->xero_contact_id,
                'name' => $c->name,
            ])
            ->values()
            ->all();
    }

    public function paginate(ExpenseLineReviewListCriteria $criteria, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery($criteria);

        return $query
            ->select([
                'el.id',
                'el.source_kind',
                'el.parent_transaction_id',
                'el.line_item_id',
                'el.occurred_on',
                'el.line_amount',
                'el.currency_code',
                'el.account_code',
                'el.description',
                'el.parent_reference',
                'el.parent_contact_id',
                'xa.name as account_name',
                'xc_parent.name as parent_contact_name',
                'ltl.contact_id as linked_contact_id',
                'ltl.target_transaction_id as billable_link_target_transaction_id',
                'xc_link.name as linked_contact_name',
                'lr.id as review_id',
                'lr.is_reviewed as is_reviewed',
                'lr.needs_invoicing',
                'lr.selected_contact_id',
                'lr.notes',
                'lr.xero_push_error',
                'xc_sel.name as selected_contact_name',
            ])
            ->orderBy($this->sortSqlColumn($criteria->sortColumn), $this->sortDirectionSql($criteria->sortDirection))
            ->orderBy('el.parent_transaction_id')
            ->orderBy('el.line_item_id')
            ->paginate($perPage);
    }

    private function baseQuery(ExpenseLineReviewListCriteria $c): Builder
    {
        $query = DB::table('expense_lines as el')
            ->where('el.organisation_id', $c->organisationId)
            ->whereBetween('el.occurred_on', [$c->dateFrom, $c->dateTo])
            ->leftJoin('linked_transaction_lines as ltl', function ($join): void {
                $join->on('ltl.organisation_id', '=', 'el.organisation_id')
                    ->on('ltl.source_transaction_id', '=', 'el.parent_transaction_id')
                    ->on('ltl.source_line_item_id', '=', 'el.line_item_id');
            })
            ->leftJoin('line_reviews as lr', function ($join): void {
                $join->on('lr.organisation_id', '=', 'el.organisation_id')
                    ->on('lr.source_kind', '=', 'el.source_kind')
                    ->on('lr.parent_transaction_id', '=', 'el.parent_transaction_id')
                    ->on('lr.line_item_id', '=', 'el.line_item_id');
            })
            ->leftJoin('xero_contacts as xc_link', function ($join): void {
                $join->on('xc_link.organisation_id', '=', 'el.organisation_id')
                    ->whereColumn('xc_link.xero_contact_id', 'ltl.contact_id');
            })
            ->leftJoin('xero_contacts as xc_sel', function ($join): void {
                $join->on('xc_sel.organisation_id', '=', 'el.organisation_id')
                    ->whereColumn('xc_sel.xero_contact_id', 'lr.selected_contact_id');
            })
            ->leftJoin('xero_contacts as xc_parent', function ($join): void {
                $join->on('xc_parent.organisation_id', '=', 'el.organisation_id')
                    ->whereColumn('xc_parent.xero_contact_id', 'el.parent_contact_id');
            })
            ->leftJoin('xero_accounts as xa', function ($join): void {
                $join->on('xa.organisation_id', '=', 'el.organisation_id')
                    ->whereRaw('el.account_id IS NOT NULL AND LOWER(xa.xero_account_id) = LOWER(el.account_id)');
            });

        if ($c->hasLink === 'yes') {
            $query->whereNotNull('ltl.id');
        } elseif ($c->hasLink === 'no') {
            $query->whereNull('ltl.id');
        }

        if ($c->needsInvoicing === 'yes') {
            $query->where('lr.needs_invoicing', true);
        } elseif ($c->needsInvoicing === 'no') {
            $query->where(function ($q): void {
                $q->whereNull('lr.id')->orWhere('lr.needs_invoicing', false);
            });
        }

        if ($c->reviewed === 'yes') {
            $query->where('lr.is_reviewed', true);
        } elseif ($c->reviewed === 'no') {
            $query->where(function ($q): void {
                $q->whereNull('lr.id')->orWhere('lr.is_reviewed', false);
            });
        }

        if ($c->search !== '') {
            $term = '%'.addcslashes($c->search, '%_\\').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('el.description', 'like', $term)
                    ->orWhere('el.parent_reference', 'like', $term)
                    ->orWhere('el.account_code', 'like', $term)
                    ->orWhere('xc_sel.name', 'like', $term)
                    ->orWhere('xc_link.name', 'like', $term)
                    ->orWhere('xc_parent.name', 'like', $term)
                    ->orWhere('xa.name', 'like', $term);
            });
        }

        return $query;
    }

    private function sortSqlColumn(string $sortColumn): string
    {
        $sortMap = [
            'occurred_on' => 'el.occurred_on',
            'source_kind' => 'el.source_kind',
            'parent_reference' => 'el.parent_reference',
            'account_code' => 'el.account_code',
            'account_name' => 'xa.name',
            'description' => 'el.description',
            'line_amount' => 'el.line_amount',
            'parent_contact_name' => 'xc_parent.name',
            'linked_contact_name' => 'xc_link.name',
            'selected_contact_name' => 'xc_sel.name',
            'notes' => 'lr.notes',
            'is_reviewed' => 'lr.is_reviewed',
        ];

        return $sortMap[$sortColumn] ?? 'el.occurred_on';
    }

    private function sortDirectionSql(string $direction): string
    {
        return $direction === 'asc' ? 'asc' : 'desc';
    }
}
