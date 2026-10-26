<?php

namespace Tests\Unit\Services\Xero;

use App\Models\ExpenseLine;
use App\Models\Organisation;
use App\Models\XeroConnection;
use App\Models\XeroContact;
use App\Queries\ExpenseReview\ExpenseLineReviewListQuery;
use App\Services\Xero\XeroSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class XeroSyncServiceCurrencyAndContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_uses_organisation_base_currency_and_flags_archived_contacts(): void
    {
        $org = Organisation::create([
            'name' => 'Kiwi Org',
            'slug' => 'kiwi-org',
            'xero_tenant_id' => (string) Str::uuid(),
        ]);
        XeroConnection::withoutGlobalScopes()->create([
            'organisation_id' => $org->id,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_type' => 'Bearer',
        ]);

        $accountId = (string) Str::uuid();
        $activeId = (string) Str::uuid();
        $archivedId = (string) Str::uuid();

        Http::fake(function (Request $request) use ($accountId, $activeId, $archivedId) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $firstPage = ($query['page'] ?? '1') === '1';
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return Http::response(match (true) {
                str_ends_with($path, '/Organisation') => ['Organisations' => [['ShortCode' => '!abc', 'BaseCurrency' => 'NZD']]],
                str_ends_with($path, '/Accounts') => ['Accounts' => [['AccountID' => $accountId, 'Code' => '400', 'Type' => 'EXPENSE']]],
                str_ends_with($path, '/Contacts') => ['Contacts' => $firstPage ? [
                    ['ContactID' => $activeId, 'Name' => 'Active Co', 'ContactStatus' => 'ACTIVE'],
                    ['ContactID' => $archivedId, 'Name' => 'Old Co', 'ContactStatus' => 'ARCHIVED'],
                ] : []],
                str_ends_with($path, '/BankTransactions') => ['BankTransactions' => $firstPage ? [[
                    'BankTransactionID' => (string) Str::uuid(),
                    'Date' => '2026-01-15',
                    'LineItems' => [['LineItemID' => (string) Str::uuid(), 'AccountID' => $accountId, 'LineAmount' => 12.5]],
                ]] : []],
                str_ends_with($path, '/Invoices') => ['Invoices' => []],
                default => ['LinkedTransactions' => []],
            });
        });

        app(XeroSyncService::class)->sync($org, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('NZD', $org->fresh()->base_currency);
        $this->assertSame('NZD', ExpenseLine::withoutGlobalScopes()->where('organisation_id', $org->id)->value('currency_code'));

        $this->assertFalse(XeroContact::withoutGlobalScopes()->where('xero_contact_id', $activeId)->first()->is_archived);
        $this->assertTrue(XeroContact::withoutGlobalScopes()->where('xero_contact_id', $archivedId)->first()->is_archived);
        $this->assertSame(
            [['id' => $activeId, 'name' => 'Active Co']],
            (new ExpenseLineReviewListQuery)->contactsForPicker($org->id),
        );
    }
}
