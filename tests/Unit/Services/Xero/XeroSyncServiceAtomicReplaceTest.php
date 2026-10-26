<?php

namespace Tests\Unit\Services\Xero;

use App\Models\ExpenseLine;
use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Services\Xero\XeroSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroSyncServiceAtomicReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_xero_request_keeps_existing_expense_lines(): void
    {
        $org = Organisation::create([
            'name' => 'Atomic Org',
            'slug' => 'atomic-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        DB::table('expense_lines')->insert([
            'organisation_id' => $org->id,
            'source_kind' => 'spend',
            'parent_transaction_id' => (string) Str::uuid(),
            'line_item_id' => (string) Str::uuid(),
            'occurred_on' => '2026-01-15',
            'line_amount' => 10,
            'currency_code' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'api.xero.com/api.xro/2.0/Organisation*' => Http::response(['Organisations' => []]),
            'api.xero.com/api.xro/2.0/Accounts*' => Http::response(['Accounts' => []]),
            'api.xero.com/api.xro/2.0/Contacts*' => Http::response(['Contacts' => []]),
            'api.xero.com/api.xro/2.0/BankTransactions*' => Http::response(['Message' => 'boom'], 500),
        ]);

        try {
            app(XeroSyncService::class)->sync($org, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
            $this->fail('Expected sync to throw.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(1, ExpenseLine::withoutGlobalScopes()->where('organisation_id', $org->id)->count());
    }
}
