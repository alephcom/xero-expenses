<?php

namespace Tests\Unit;

use App\Models\ExpenseLine;
use App\Services\Xero\XeroWebTransactionUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class XeroWebTransactionUrlTest extends TestCase
{
    #[Test]
    public function returns_null_when_short_code_empty(): void
    {
        $u = new XeroWebTransactionUrl;

        $this->assertNull($u->forLine(null, ExpenseLine::SOURCE_SPEND, 'a-b-c'));
        $this->assertNull($u->forLine('', ExpenseLine::SOURCE_SPEND, 'a-b-c'));
        $this->assertNull($u->forLine('ABC', ExpenseLine::SOURCE_SPEND, ''));
    }

    #[Test]
    public function spend_uses_bank_transaction_deep_link(): void
    {
        $u = new XeroWebTransactionUrl;
        $id = 'a6e0efbb-328f-4dd2-b50a-d4aa9f1c1993';

        $url = $u->forLine('!DemoCo', ExpenseLine::SOURCE_SPEND, $id);

        $this->assertStringStartsWith('https://go.xero.com/organisationlogin/default.aspx?', $url);
        $this->assertStringContainsString('shortcode=%21DemoCo', $url);
        $this->assertStringContainsString('redirecturl=', $url);
        $this->assertStringContainsString(rawurlencode('/Bank/ViewTransaction.aspx?bankTransactionID='.$id), $url);
    }

    #[Test]
    public function bill_uses_accounts_payable_invoice_deep_link(): void
    {
        $u = new XeroWebTransactionUrl;
        $id = '11111111-2222-3333-4444-555555555555';

        $url = $u->forLine('!DemoCo', ExpenseLine::SOURCE_BILL, $id);

        $this->assertStringStartsWith('https://go.xero.com/organisationlogin/default.aspx?', $url);
        $this->assertStringContainsString('shortcode=%21DemoCo', $url);
        $this->assertStringContainsString(rawurlencode('/AccountsPayable/View.aspx?InvoiceID='.$id), $url);
    }

    #[Test]
    public function unknown_source_kind_returns_null(): void
    {
        $u = new XeroWebTransactionUrl;

        $this->assertNull($u->forLine('ABC', 'other', 'x'));
    }
}
