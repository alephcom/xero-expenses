<?php

namespace Tests\Unit\Queries\ExpenseReview;

use App\Models\Organisation;
use App\Queries\ExpenseReview\OrganisationExpenseTotalsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganisationExpenseTotalsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zeros_for_unknown_or_empty_org_ids(): void
    {
        $query = new OrganisationExpenseTotalsQuery;

        $this->assertSame([], $query->forOrganisationIds([]));

        $org = Organisation::query()->create([
            'name' => 'Solo',
            'slug' => 'solo',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $out = $query->forOrganisationIds([$org->id]);
        $this->assertSame([
            'not_reviewed' => '0.00',
            'flagged_not_invoiced' => '0.00',
            'invoiced' => '0.00',
            'currency_code' => null,
            'mixed_currency' => false,
        ], $out[$org->id]);
    }

    public function test_not_reviewed_excludes_reviewed_lines(): void
    {
        $org = $this->makeOrg();
        $day = Carbon::now()->subDay()->toDateString();

        $p1 = (string) Str::uuid();
        $l1 = (string) Str::uuid();
        $p2 = (string) Str::uuid();
        $l2 = (string) Str::uuid();

        $this->insertExpenseLine($org->id, $p1, $l1, $day, '100.0000', 'USD');
        $this->insertExpenseLine($org->id, $p2, $l2, $day, '50.0000', 'USD');
        $this->insertLineReview($org->id, $p2, $l2, needsInvoicing: false, isReviewed: true);

        $out = (new OrganisationExpenseTotalsQuery)->forOrganisationIds([$org->id]);

        $this->assertSame('100.00', $out[$org->id]['not_reviewed']);
        $this->assertSame('0.00', $out[$org->id]['flagged_not_invoiced']);
        $this->assertSame('0.00', $out[$org->id]['invoiced']);
    }

    public function test_flagged_includes_needs_invoicing_when_not_allocated(): void
    {
        $org = $this->makeOrg();
        $day = Carbon::now()->subDay()->toDateString();
        $p = (string) Str::uuid();
        $l = (string) Str::uuid();

        $this->insertExpenseLine($org->id, $p, $l, $day, '25.5000', 'USD');
        $this->insertLineReview($org->id, $p, $l, needsInvoicing: true, isReviewed: false);

        $out = (new OrganisationExpenseTotalsQuery)->forOrganisationIds([$org->id]);

        $this->assertSame('25.50', $out[$org->id]['flagged_not_invoiced']);
    }

    public function test_flagged_includes_open_customer_link_without_app_needs_invoicing(): void
    {
        $org = $this->makeOrg();
        $day = Carbon::now()->subDay()->toDateString();
        $p = (string) Str::uuid();
        $l = (string) Str::uuid();
        $contact = (string) Str::uuid();

        $this->insertExpenseLine($org->id, $p, $l, $day, '40.0000', 'USD');
        $this->insertLineReview($org->id, $p, $l, needsInvoicing: false, isReviewed: true);
        $this->insertLinkedLine($org->id, $p, $l, $contact, targetTransactionId: null);

        $out = (new OrganisationExpenseTotalsQuery)->forOrganisationIds([$org->id]);

        $this->assertSame('40.00', $out[$org->id]['flagged_not_invoiced']);
        $this->assertSame('0.00', $out[$org->id]['invoiced']);
    }

    public function test_invoiced_sums_lines_with_target_transaction(): void
    {
        $org = $this->makeOrg();
        $day = Carbon::now()->subDay()->toDateString();
        $p = (string) Str::uuid();
        $l = (string) Str::uuid();
        $contact = (string) Str::uuid();
        $target = (string) Str::uuid();

        $this->insertExpenseLine($org->id, $p, $l, $day, '60.0000', 'USD');
        $this->insertLinkedLine($org->id, $p, $l, $contact, targetTransactionId: $target);

        $out = (new OrganisationExpenseTotalsQuery)->forOrganisationIds([$org->id]);

        $this->assertSame('60.00', $out[$org->id]['invoiced']);
        $this->assertSame('0.00', $out[$org->id]['flagged_not_invoiced']);
    }

    public function test_mixed_currency_sets_mixed_flag_and_null_currency_code(): void
    {
        $org = $this->makeOrg();
        $day = Carbon::now()->subDay()->toDateString();
        $p1 = (string) Str::uuid();
        $l1 = (string) Str::uuid();
        $p2 = (string) Str::uuid();
        $l2 = (string) Str::uuid();

        $this->insertExpenseLine($org->id, $p1, $l1, $day, '10.0000', 'USD');
        $this->insertExpenseLine($org->id, $p2, $l2, $day, '20.0000', 'EUR');

        $out = (new OrganisationExpenseTotalsQuery)->forOrganisationIds([$org->id]);

        $this->assertTrue($out[$org->id]['mixed_currency']);
        $this->assertNull($out[$org->id]['currency_code']);
    }

    private function makeOrg(): Organisation
    {
        return Organisation::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-'.Str::lower(Str::random(6)),
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
    }

    private function insertExpenseLine(
        int $organisationId,
        string $parentId,
        string $lineId,
        string $day,
        string $amount,
        string $currency,
    ): void {
        DB::table('expense_lines')->insert([
            'organisation_id' => $organisationId,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => $day,
            'line_amount' => $amount,
            'currency_code' => $currency,
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Test',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLineReview(
        int $organisationId,
        string $parentId,
        string $lineId,
        bool $needsInvoicing,
        bool $isReviewed,
    ): void {
        DB::table('line_reviews')->insert([
            'organisation_id' => $organisationId,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => $needsInvoicing,
            'is_reviewed' => $isReviewed,
            'selected_contact_id' => null,
            'notes' => null,
            'user_id' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLinkedLine(
        int $organisationId,
        string $parentId,
        string $lineId,
        string $contactId,
        ?string $targetTransactionId,
    ): void {
        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $organisationId,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactId,
            'target_transaction_id' => $targetTransactionId,
            'target_line_item_id' => $targetTransactionId !== null ? (string) Str::uuid() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
