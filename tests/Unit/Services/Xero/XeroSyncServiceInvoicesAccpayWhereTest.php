<?php

namespace Tests\Unit\Services\Xero;

use App\Services\Xero\XeroApiClient;
use App\Services\Xero\XeroSyncService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class XeroSyncServiceInvoicesAccpayWhereTest extends TestCase
{
    public function test_accpay_invoice_where_includes_allowed_statuses_and_date_range(): void
    {
        $service = new XeroSyncService($this->createMock(XeroApiClient::class));
        $method = new ReflectionMethod(XeroSyncService::class, 'invoicesAccpayWhere');
        $method->setAccessible(true);

        $from = Carbon::parse('2026-03-01');
        $to = Carbon::parse('2026-03-31');
        $where = $method->invoke($service, $from, $to);

        $this->assertStringContainsString('Type=="ACCPAY"', $where);
        $this->assertStringContainsString('Status=="DRAFT"', $where);
        $this->assertStringContainsString('Status=="SUBMITTED"', $where);
        $this->assertStringContainsString('Status=="AUTHORISED"', $where);
        $this->assertStringContainsString('Status=="PAID"', $where);
        $this->assertStringNotContainsString('DELETED', $where);
        $this->assertStringNotContainsString('VOIDED', $where);
        $this->assertStringContainsString('Date>=DateTime(2026,03,01)', $where);
        $this->assertStringContainsString('Date<=DateTime(2026,03,31)', $where);
    }
}
