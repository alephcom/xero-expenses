<?php

namespace Tests\Feature\Queries\ExpenseReview;

use App\Models\Organisation;
use App\Queries\ExpenseReview\ExpenseLineReviewListCriteria;
use App\Queries\ExpenseReview\ExpenseLineReviewListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseLineReviewListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_selected_contact_name(): void
    {
        $org = Organisation::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $contactId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $day = Carbon::now()->subDay()->toDateString();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'ZetaUnique Corp Name',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => 10,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Office supplies',
            'parent_reference' => 'REF-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('line_reviews')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
            'user_id' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = new ExpenseLineReviewListQuery;
        $criteria = new ExpenseLineReviewListCriteria(
            organisationId: $org->id,
            dateFrom: $day,
            dateTo: $day,
            hasLink: 'all',
            needsInvoicing: 'all',
            reviewed: 'all',
            search: 'ZetaUnique',
            sortColumn: 'occurred_on',
            sortDirection: 'desc',
        );

        $paginator = $query->paginate($criteria);
        $this->assertCount(1, $paginator->items());
        $row = $paginator->items()[0];
        $this->assertSame('Office supplies', $row->description);
        $this->assertSame('ZetaUnique Corp Name', $row->selected_contact_name);
    }

    public function test_has_link_yes_excludes_unlinked_lines(): void
    {
        $org = Organisation::create([
            'name' => 'Link Org',
            'slug' => 'link-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $day = Carbon::now()->subDay()->toDateString();
        $contactId = (string) Str::uuid();

        DB::table('xero_contacts')->insert([
            'organisation_id' => $org->id,
            'xero_contact_id' => $contactId,
            'name' => 'Link Contact',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linkedParent = (string) Str::uuid();
        $linkedLine = (string) Str::uuid();
        $unlinkedParent = (string) Str::uuid();
        $unlinkedLine = (string) Str::uuid();

        foreach ([
            [$linkedParent, $linkedLine, 'Linked row'],
            [$unlinkedParent, $unlinkedLine, 'Unlinked row'],
        ] as [$parent, $line, $desc]) {
            DB::table('expense_lines')->insert([
                'organisation_id' => $org->id,
                'source_kind' => 'spend',
                'parent_transaction_id' => $parent,
                'line_item_id' => $line,
                'occurred_on' => $day,
                'line_amount' => 1,
                'currency_code' => 'USD',
                'account_id' => null,
                'account_code' => '4000',
                'description' => $desc,
                'parent_reference' => 'R',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'source_transaction_id' => $linkedParent,
            'source_line_item_id' => $linkedLine,
            'contact_id' => $contactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = new ExpenseLineReviewListQuery;
        $criteria = new ExpenseLineReviewListCriteria(
            organisationId: $org->id,
            dateFrom: $day,
            dateTo: $day,
            hasLink: 'yes',
            needsInvoicing: 'all',
            reviewed: 'all',
            search: '',
            sortColumn: 'occurred_on',
            sortDirection: 'desc',
        );

        $paginator = $query->paginate($criteria);
        $this->assertCount(1, $paginator->items());
        $this->assertSame('Linked row', $paginator->items()[0]->description);
    }

    public function test_sort_by_line_amount_asc_orders_low_before_high(): void
    {
        $org = Organisation::create([
            'name' => 'Sort Org',
            'slug' => 'sort-org-query',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $day = Carbon::now()->subDay()->toDateString();

        foreach ([
            ['parent' => (string) Str::uuid(), 'line' => (string) Str::uuid(), 'amount' => 100.0, 'desc' => 'High line'],
            ['parent' => (string) Str::uuid(), 'line' => (string) Str::uuid(), 'amount' => 5.0, 'desc' => 'Low line'],
        ] as $r) {
            DB::table('expense_lines')->insert([
                'organisation_id' => $org->id,
                'source_kind' => 'spend',
                'parent_transaction_id' => $r['parent'],
                'line_item_id' => $r['line'],
                'occurred_on' => $day,
                'line_amount' => $r['amount'],
                'currency_code' => 'USD',
                'account_id' => null,
                'account_code' => '4000',
                'description' => $r['desc'],
                'parent_reference' => 'R',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $query = new ExpenseLineReviewListQuery;
        $criteria = new ExpenseLineReviewListCriteria(
            organisationId: $org->id,
            dateFrom: $day,
            dateTo: $day,
            hasLink: 'all',
            needsInvoicing: 'all',
            reviewed: 'all',
            search: '',
            sortColumn: 'line_amount',
            sortDirection: 'asc',
        );

        $items = $query->paginate($criteria)->items();
        $this->assertSame('Low line', $items[0]->description);
        $this->assertSame('High line', $items[1]->description);
    }

    public function test_contacts_for_picker_returns_ordered_id_name_pairs(): void
    {
        $org = Organisation::create([
            'name' => 'Contacts Org',
            'slug' => 'contacts-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $idB = (string) Str::uuid();
        $idA = (string) Str::uuid();

        DB::table('xero_contacts')->insert([
            [
                'organisation_id' => $org->id,
                'xero_contact_id' => $idB,
                'name' => 'Bravo',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'organisation_id' => $org->id,
                'xero_contact_id' => $idA,
                'name' => 'Alpha',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $query = new ExpenseLineReviewListQuery;
        $picker = $query->contactsForPicker($org->id);

        $this->assertSame([
            ['id' => $idA, 'name' => 'Alpha'],
            ['id' => $idB, 'name' => 'Bravo'],
        ], $picker);
    }
}
