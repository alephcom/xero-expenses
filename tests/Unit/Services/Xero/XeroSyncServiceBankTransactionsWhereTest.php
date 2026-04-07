<?php

namespace Tests\Unit\Services\Xero;

use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSyncService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class XeroSyncServiceBankTransactionsWhereTest extends TestCase
{
    public function test_spend_bank_transaction_where_requires_authorised_and_excludes_deleted_by_api_filter(): void
    {
        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $method = new ReflectionMethod(XeroSyncService::class, 'bankTransactionsSpendWhere');
        $method->setAccessible(true);

        $from = Carbon::parse('2026-01-15');
        $to = Carbon::parse('2026-01-20');
        $where = $method->invoke($service, $from, $to);

        $this->assertStringContainsString('Type=="SPEND"', $where);
        $this->assertStringContainsString('Status=="AUTHORISED"', $where);
        $this->assertStringContainsString('Date>=DateTime(2026,01,15)', $where);
        $this->assertStringContainsString('Date<=DateTime(2026,01,20)', $where);
    }
}
