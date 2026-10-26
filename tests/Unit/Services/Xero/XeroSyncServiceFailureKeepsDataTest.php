<?php

namespace Tests\Unit\Services\Xero;

use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroSyncServiceFailureKeepsDataTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organisation::create([
            'name' => 'Sync Org',
            'slug' => 'sync-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);

        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $this->organisation->id,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        $this->accountId = (string) Str::uuid();
        $parentId = (string) Str::uuid();
        $lineId = (string) Str::uuid();

        DB::table('expense_lines')->insert([
            'organisation_id' => $this->organisation->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => $parentId,
            'line_item_id' => $lineId,
            'occurred_on' => '2026-03-15',
            'line_amount' => 42,
            'currency_code' => 'USD',
            'account_id' => $this->accountId,
            'account_code' => '400',
            'description' => 'Existing line',
            'parent_reference' => 'R1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('linked_transaction_lines')->insert([
            'organisation_id' => $this->organisation->id,
            'xero_linked_transaction_id' => (string) Str::uuid(),
            'source_transaction_id' => $parentId,
            'source_line_item_id' => $lineId,
            'contact_id' => (string) Str::uuid(),
            'target_transaction_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeXero(array $overrides): void
    {
        $base = 'api.xero.com/api.xro/2.0/';
        $empty = fn (string $key) => Http::response([$key => []]);

        Http::fake([
            $base.'Organisation*' => Http::response(['Organisations' => [['ShortCode' => '!abc']]]),
            $base.'Accounts*' => Http::response(['Accounts' => [
                ['AccountID' => $this->accountId, 'Code' => '400', 'Name' => 'Expenses', 'Type' => 'EXPENSE'],
            ]]),
            $base.'Contacts*' => $empty('Contacts'),
            $base.'BankTransactions*' => $empty('BankTransactions'),
            $base.'Invoices*' => $empty('Invoices'),
            $base.'LinkedTransactions*' => $empty('LinkedTransactions'),
            ...$overrides,
        ]);
    }

    private function runSync(): void
    {
        try {
            app(XeroSyncService::class)->sync(
                $this->organisation,
                Carbon::parse('2026-03-01'),
                Carbon::parse('2026-03-31'),
            );
            $this->fail('Expected the sync to fail.');
        } catch (RequestException) {
            // expected
        }
    }

    public function test_failed_expense_line_fetch_keeps_existing_lines(): void
    {
        $this->fakeXero([
            'api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Title' => 'Rate limit'], 429),
        ]);

        $this->runSync();

        $this->assertSame(1, DB::table('expense_lines')->where('organisation_id', $this->organisation->id)->count());
    }

    public function test_failed_linked_transaction_fetch_keeps_existing_links(): void
    {
        $this->fakeXero([
            'api.xero.com/api.xro/2.0/BankTransactions*' => Http::sequence()
                ->push(['BankTransactions' => [$this->bankTransactionMatchingExistingLine()]])
                ->push(['BankTransactions' => []]),
            'api.xero.com/api.xro/2.0/LinkedTransactions*' => Http::sequence()
                ->push(['LinkedTransactions' => [[
                    'LinkedTransactionID' => (string) Str::uuid(),
                    'SourceTransactionID' => (string) Str::uuid(),
                    'SourceLineItemID' => (string) Str::uuid(),
                ]]])
                ->push(['Title' => 'Server error'], 500),
        ]);

        $this->runSync();

        $link = DB::table('linked_transaction_lines')->where('organisation_id', $this->organisation->id)->get();
        $this->assertCount(1, $link);
        $this->assertNotNull($link->first()->target_transaction_id);
    }

    public function test_failed_contacts_fetch_keeps_existing_contacts(): void
    {
        DB::table('xero_contacts')->insert([
            'organisation_id' => $this->organisation->id,
            'xero_contact_id' => (string) Str::uuid(),
            'name' => 'Existing Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeXero([
            'api.xero.com/api.xro/2.0/Contacts*' => Http::sequence()
                ->push(['Contacts' => [['ContactID' => (string) Str::uuid(), 'Name' => 'Page one']]])
                ->push(['Title' => 'Server error'], 500),
        ]);

        $this->runSync();

        $this->assertSame(
            ['Existing Customer'],
            DB::table('xero_contacts')->where('organisation_id', $this->organisation->id)->pluck('name')->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bankTransactionMatchingExistingLine(): array
    {
        $existing = DB::table('expense_lines')->where('organisation_id', $this->organisation->id)->first();

        return [
            'BankTransactionID' => $existing->parent_transaction_id,
            'Date' => '2026-03-15',
            'CurrencyCode' => 'USD',
            'LineItems' => [[
                'LineItemID' => $existing->line_item_id,
                'AccountID' => $this->accountId,
                'AccountCode' => '400',
                'LineAmount' => 42,
                'Description' => 'Existing line',
            ]],
        ];
    }
}
