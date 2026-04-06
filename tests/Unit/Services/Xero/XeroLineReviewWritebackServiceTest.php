<?php

namespace Tests\Unit\Services\Xero;

use App\Models\LineReview;
use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroLineReviewWritebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroLineReviewWritebackServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_posts_new_linked_transaction_when_none_exists(): void
    {
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();

        $org = Organisation::create([
            'name' => 'API Org',
            'slug' => 'api-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

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
            'description' => 'Coffee',
            'parent_reference' => 'R1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $review = LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => true,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['LinkedTransactions' => []])
                ->push(['LinkedTransactions' => [['LinkedTransactionID' => (string) Str::uuid()]]])
                ->push(['LinkedTransactions' => []]),
        ]);

        $service = app(XeroLineReviewWritebackService::class);
        $service->push($org->fresh(), $review->fresh());

        Http::assertSent(function (Request $request) use ($parentId, $lineId, $contactId): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            if (! str_contains($request->url(), 'LinkedTransactions')) {
                return false;
            }
            if (str_contains($request->url(), 'LinkedTransactions/')) {
                return false;
            }
            $data = $request->data();

            return ($data['SourceTransactionID'] ?? null) === $parentId
                && ($data['SourceLineItemID'] ?? null) === $lineId
                && ($data['ContactID'] ?? null) === $contactId;
        });
    }

    public function test_push_deletes_linked_transaction_when_invoicing_turned_off(): void
    {
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $linkId = (string) Str::uuid();

        $org = Organisation::create([
            'name' => 'Del Org',
            'slug' => 'del-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

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
            'description' => 'Tea',
            'parent_reference' => 'R2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $review = LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['LinkedTransactions' => [[
                    'LinkedTransactionID' => $linkId,
                    'SourceTransactionID' => $parentId,
                    'SourceLineItemID' => $lineId,
                    'SourceTransactionTypeCode' => 'SPEND',
                    'ContactID' => $contactId,
                ]]])
                ->push([])
                ->push(['LinkedTransactions' => []]),
        ]);

        $service = app(XeroLineReviewWritebackService::class);
        $service->push($org->fresh(), $review->fresh());

        Http::assertSent(function (Request $request) use ($linkId): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'LinkedTransactions/'.$linkId);
        });
    }

    public function test_push_does_not_delete_linked_transaction_when_target_transaction_present(): void
    {
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $contactId = (string) Str::uuid();
        $linkId = (string) Str::uuid();
        $targetId = (string) Str::uuid();

        $org = Organisation::create([
            'name' => 'Target Org',
            'slug' => 'target-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

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
            'description' => 'Tea',
            'parent_reference' => 'R2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $review = LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => $contactId,
            'notes' => null,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['LinkedTransactions' => [[
                    'LinkedTransactionID' => $linkId,
                    'SourceTransactionID' => $parentId,
                    'SourceLineItemID' => $lineId,
                    'SourceTransactionTypeCode' => 'SPEND',
                    'ContactID' => $contactId,
                    'TargetTransactionID' => $targetId,
                ]]])
                ->push(['LinkedTransactions' => []]),
        ]);

        $service = app(XeroLineReviewWritebackService::class);
        $service->push($org->fresh(), $review->fresh());

        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'LinkedTransactions');
        });
    }

    public function test_push_puts_history_when_notes_present(): void
    {
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        $org = Organisation::create([
            'name' => 'Note Org',
            'slug' => 'note-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => now()->toDateString(),
            'line_amount' => 5,
            'currency_code' => 'USD',
            'account_id' => null,
            'account_code' => '4000',
            'description' => 'Paper',
            'parent_reference' => 'R3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $review = LineReview::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'needs_invoicing' => false,
            'is_reviewed' => false,
            'selected_contact_id' => null,
            'notes' => 'Client approval attached',
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['LinkedTransactions' => []])
                ->push([])
                ->push(['LinkedTransactions' => []]),
        ]);

        $service = app(XeroLineReviewWritebackService::class);
        $service->push($org->fresh(), $review->fresh());

        Http::assertSent(function (Request $request) use ($parentId, $lineId): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            if (! str_contains($request->url(), 'BankTransactions/'.$parentId.'/History')) {
                return false;
            }
            $data = $request->data();
            $details = $data['HistoryRecords'][0]['Details'] ?? '';

            return str_contains((string) $details, 'Client approval attached')
                && str_contains((string) $details, mb_substr($lineId, -8));
        });

        $review->refresh();
        $this->assertNotNull($review->xero_last_pushed_notes);
    }
}
