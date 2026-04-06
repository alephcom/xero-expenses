<?php

namespace App\Services\Xero;

use App\Models\ExpenseLine;

class XeroWebTransactionUrl
{
    private const LOGIN_BASE = 'https://go.xero.com/organisationlogin/default.aspx';

    /**
     * Deep link into Xero for the parent transaction (bill or bank spend).
     *
     * @see https://developer.xero.com/documentation/guides/how-to-guides/deep-link-xero/
     * @see https://developer.xero.com/documentation/best-practices/user-experience/transaction-urls/
     */
    public function forLine(?string $shortCode, string $sourceKind, string $parentTransactionId): ?string
    {
        if ($shortCode === null || $shortCode === '' || $parentTransactionId === '') {
            return null;
        }

        if ($sourceKind === ExpenseLine::SOURCE_BILL) {
            $redirectPath = '/AccountsPayable/View.aspx?InvoiceID='.$parentTransactionId;
        } elseif ($sourceKind === ExpenseLine::SOURCE_SPEND) {
            $redirectPath = '/Bank/ViewTransaction.aspx?bankTransactionID='.$parentTransactionId;
        } else {
            return null;
        }

        return self::LOGIN_BASE
            .'?shortcode='.rawurlencode($shortCode)
            .'&redirecturl='.rawurlencode($redirectPath);
    }
}
