<?php

namespace App\Queries\ExpenseReview;

use Illuminate\Support\Facades\DB;

class OrganisationExpenseTotalsQuery
{
    /**
     * @param  list<int>  $organisationIds
     * @return array<int, array{
     *     not_reviewed: string,
     *     flagged_not_invoiced: string,
     *     invoiced: string,
     *     currency_code: ?string,
     *     mixed_currency: bool
     * }>
     */
    public function forOrganisationIds(array $organisationIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $organisationIds)));

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'not_reviewed' => '0.00',
                'flagged_not_invoiced' => '0.00',
                'invoiced' => '0.00',
                'currency_code' => null,
                'mixed_currency' => false,
            ];
        }

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('expense_lines as el')
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
            ->whereIn('el.organisation_id', $ids)
            ->groupBy('el.organisation_id')
            ->selectRaw(
                'el.organisation_id,
                SUM(CASE WHEN (lr.id IS NULL OR lr.is_reviewed = 0) THEN el.line_amount ELSE 0 END) AS not_reviewed_total,
                SUM(CASE WHEN (ltl.id IS NULL OR ltl.target_transaction_id IS NULL)
                    AND (COALESCE(lr.needs_invoicing, 0) = 1 OR (ltl.contact_id IS NOT NULL AND ltl.target_transaction_id IS NULL))
                    THEN el.line_amount ELSE 0 END) AS flagged_not_invoiced_total,
                SUM(CASE WHEN ltl.target_transaction_id IS NOT NULL THEN el.line_amount ELSE 0 END) AS invoiced_total,
                COUNT(DISTINCT el.currency_code) AS currency_count,
                MAX(el.currency_code) AS sample_currency'
            )
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->organisation_id;
            $currencyCount = (int) $row->currency_count;

            $out[$id] = [
                'not_reviewed' => $this->formatMoney($row->not_reviewed_total),
                'flagged_not_invoiced' => $this->formatMoney($row->flagged_not_invoiced_total),
                'invoiced' => $this->formatMoney($row->invoiced_total),
                'currency_code' => $currencyCount === 1 ? (string) $row->sample_currency : null,
                'mixed_currency' => $currencyCount > 1,
            ];
        }

        return $out;
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
