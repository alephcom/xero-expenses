<?php

namespace Tests\Unit\Services\Xero;

use App\Models\LineReview;
use App\Models\Organisation;
use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroSyncServiceReconcileLineReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_creates_line_review_when_xero_link_has_contact(): void
    {
        $org = Organisation::create([
            'name' => 'Reconcile Org',
            'slug' => 'reconcile-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => now()->toDateString(),
            'line_amount' => 10,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Item',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $service->reconcileLineReviewInvoiceFlagsFromLinkedTransactions($org);

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertTrue($review->needs_invoicing);
        $this->assertSame($contactId, $review->selected_contact_id);
        $this->assertNull($review->reviewed_at);
    }

    public function test_reconcile_updates_flags_but_preserves_notes_and_reviewed(): void
    {
        $org = Organisation::create([
            'name' => 'Preserve Org',
            'slug' => 'preserve-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactA = (string) Str::uuid();
        $contactB = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'bill',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => now()->toDateString(),
            'line_amount' => 20,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '5000',
            'description' => 'Bill line',
            'parent_reference' => 'INV-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'bill',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => true,
            'selected_contact_id' => $contactA,
            'notes' => 'Keep this note',
            'user_id' => null,
            'reviewed_at' => $reviewedAt = now()->subDay()->startOfSecond(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactB,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $service->reconcileLineReviewInvoiceFlagsFromLinkedTransactions($org);

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertTrue($review->needs_invoicing);
        $this->assertSame($contactB, $review->selected_contact_id);
        $this->assertTrue($review->is_reviewed);
        $this->assertSame('Keep this note', $review->notes);
        $this->assertSame($reviewedAt->toDateTimeString(), $review->reviewed_at->toDateTimeString());
    }

    public function test_reconcile_ignores_links_without_contact(): void
    {
        $org = Organisation::create([
            'name' => 'Draft Org',
            'slug' => 'draft-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => now()->toDateString(),
            'line_amount' => 1,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'x',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $service->reconcileLineReviewInvoiceFlagsFromLinkedTransactions($org);

        $this->assertSame(0, LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }

    public function test_reconcile_allocated_link_sets_needs_invoicing_false(): void
    {
        $org = Organisation::create([
            'name' => 'Allocated Org',
            'slug' => 'allocated-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $targetId = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => now()->toDateString(),
            'line_amount' => 10,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Item',
            'parent_reference' => 'R',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $org->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => $contactId,
            'target_transaction_id' => $targetId,
            'target_line_item_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $service->reconcileLineReviewInvoiceFlagsFromLinkedTransactions($org);

        $review = LineReview::withoutGlobalScopes()->where('organisation_id', $org->id)->first();
        $this->assertNotNull($review);
        $this->assertFalse($review->needs_invoicing);
        $this->assertSame($contactId, $review->selected_contact_id);
    }
}
