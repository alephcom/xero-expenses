<?php

namespace App\Services\Xero;

use App\Models\ExpenseLine;
use App\Models\LineReview;
use App\Models\LinkedTransactionLine;
use App\Models\Organisation;
use App\Models\SyncRun;
use App\Models\XeroAccount;
use App\Models\XeroContact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class XeroSyncService
{
    /** @var list<string> */
    private const EXPENSE_ACCOUNT_TYPES = ['EXPENSE', 'OVERHEADS', 'DIRECTCOSTS'];

    public function __construct(
        private readonly XeroApiClient $client,
    ) {}

    public function sync(Organisation $organisation, Carbon $dateFrom, Carbon $dateTo, ?int $syncRunId = null): void
    {
        $syncRun = $this->resolveSyncRun($organisation, $dateFrom, $dateTo, $syncRunId);

        try {
            $this->touchSyncStep($syncRun, 'organisation');
            $this->syncOrganisationShortCode($organisation);

            $this->touchSyncStep($syncRun, 'accounts');
            $this->syncAccounts($organisation);
            $expenseAccountIds = $this->expenseAccountIds($organisation);

            $this->touchSyncStep($syncRun, 'contacts');
            $this->syncContactsPaged($organisation);

            $this->touchSyncStep($syncRun, 'expense_lines');
            $this->replaceExpenseLinesForRange($organisation, $dateFrom, $dateTo, $expenseAccountIds);

            $this->touchSyncStep($syncRun, 'linked_transactions');
            $this->replaceLinkedTransactionLines($organisation);

            $organisation->last_synced_at = now();
            $organisation->save();

            $syncRun->update([
                'status' => SyncRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Xero sync failed', [
                'organisation_id' => $organisation->id,
                'sync_run_id' => $syncRun->id,
                'exception' => $e,
            ]);
            $userMessage = config('app.debug')
                ? $e->getMessage()
                : __('Sync failed. Please try again or contact support.');
            $syncRun->update([
                'status' => SyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => Str::limit($userMessage, 60000),
            ]);

            throw $e;
        }
    }

    private function resolveSyncRun(Organisation $organisation, Carbon $dateFrom, Carbon $dateTo, ?int $syncRunId): SyncRun
    {
        if ($syncRunId === null) {
            return SyncRun::withoutGlobalScopes()->create([
                'organisation_id' => $organisation->id,
                'status' => SyncRun::STATUS_RUNNING,
                'started_at' => now(),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ]);
        }

        $syncRun = SyncRun::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereKey($syncRunId)
            ->firstOrFail();

        if ($syncRun->status !== SyncRun::STATUS_QUEUED) {
            Log::warning('Xero sync run was not queued; refusing to reuse', [
                'organisation_id' => $organisation->id,
                'sync_run_id' => $syncRunId,
                'status' => $syncRun->status,
            ]);
            throw new \RuntimeException('Sync run is not in queued state.');
        }

        $syncRun->update([
            'status' => SyncRun::STATUS_RUNNING,
            'started_at' => now(),
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
        ]);
        $syncRun->refresh();

        return $syncRun;
    }

    private function touchSyncStep(SyncRun $syncRun, string $step): void
    {
        $syncRun->update([
            'meta' => array_merge($syncRun->meta ?? [], ['step' => $step]),
        ]);
        $syncRun->refresh();
    }

    /**
     * @return list<string>
     */
    private function syncOrganisationShortCode(Organisation $organisation): void
    {
        try {
            $json = $this->client->get($organisation, 'Organisation');
            $list = $json['Organisations'] ?? [];
            if (! is_array($list) || $list === []) {
                return;
            }
            $first = $list[0] ?? null;
            if (! is_array($first)) {
                return;
            }
            $shortCode = $first['ShortCode'] ?? null;
            if (! is_string($shortCode) || $shortCode === '') {
                return;
            }
            $organisation->xero_short_code = $shortCode;
            $organisation->save();
        } catch (Throwable $e) {
            Log::warning('Xero organisation ShortCode sync skipped', [
                'organisation_id' => $organisation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function expenseAccountIds(Organisation $organisation): array
    {
        return XeroAccount::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereIn('type', self::EXPENSE_ACCOUNT_TYPES)
            ->pluck('xero_account_id')
            ->map(fn (string $id) => strtolower($id))
            ->all();
    }

    private function syncAccounts(Organisation $organisation): void
    {
        $json = $this->client->get($organisation, 'Accounts');
        $accounts = $json['Accounts'] ?? [];
        $rows = [];
        foreach ($accounts as $acc) {
            $id = $acc['AccountID'] ?? null;
            if (! $id) {
                continue;
            }
            $rows[] = [
                'organisation_id' => $organisation->id,
                'xero_account_id' => $id,
                'code' => $acc['Code'] ?? null,
                'name' => $acc['Name'] ?? null,
                'type' => $acc['Type'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        XeroAccount::withoutGlobalScopes()->where('organisation_id', $organisation->id)->delete();
        foreach (array_chunk($rows, 250) as $chunk) {
            XeroAccount::withoutGlobalScopes()->insert($chunk);
        }
    }

    private function syncContactsPaged(Organisation $organisation): void
    {
        $rows = [];
        for ($page = 1; ; $page++) {
            $json = $this->client->get($organisation, 'Contacts', ['page' => $page]);
            $contacts = $json['Contacts'] ?? [];
            if (count($contacts) === 0) {
                break;
            }
            foreach ($contacts as $c) {
                $id = $c['ContactID'] ?? null;
                if (! $id) {
                    continue;
                }
                $rows[] = [
                    'organisation_id' => $organisation->id,
                    'xero_contact_id' => $id,
                    'name' => $c['Name'] ?? 'Unnamed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        XeroContact::withoutGlobalScopes()->where('organisation_id', $organisation->id)->delete();
        foreach (array_chunk($rows, 250) as $chunk) {
            XeroContact::withoutGlobalScopes()->insert($chunk);
        }
    }

    /**
     * @param  list<string>  $expenseAccountIds
     */
    private function replaceExpenseLinesForRange(
        Organisation $organisation,
        Carbon $dateFrom,
        Carbon $dateTo,
        array $expenseAccountIds,
    ): void {
        ExpenseLine::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->whereBetween('occurred_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->delete();

        $rows = [];
        $this->collectSpendLines($organisation, $dateFrom, $dateTo, $expenseAccountIds, $rows);
        $this->collectBillLines($organisation, $dateFrom, $dateTo, $expenseAccountIds, $rows);

        foreach (array_chunk($rows, 250) as $chunk) {
            ExpenseLine::withoutGlobalScopes()->insert($chunk);
        }
    }

    /**
     * @param  list<string>  $expenseAccountIds
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectSpendLines(
        Organisation $organisation,
        Carbon $dateFrom,
        Carbon $dateTo,
        array $expenseAccountIds,
        array &$rows,
    ): void {
        $where = 'Type=="SPEND"&&'.$this->xeroDateWhere($dateFrom, $dateTo);

        for ($page = 1; ; $page++) {
            $json = $this->client->get($organisation, 'BankTransactions', [
                'page' => $page,
                'where' => $where,
            ]);
            $list = $json['BankTransactions'] ?? [];
            if (count($list) === 0) {
                break;
            }
            foreach ($list as $bt) {
                $parentId = $bt['BankTransactionID'] ?? null;
                if (! $parentId) {
                    continue;
                }
                $date = isset($bt['Date']) ? $this->parseXeroDate($bt['Date'])->toDateString() : $dateFrom->toDateString();
                $currency = $bt['CurrencyCode'] ?? 'USD';
                $reference = $bt['Reference'] ?? ($bt['BankAccount']['Name'] ?? null);
                $lineItems = $bt['LineItems'] ?? [];
                $parentContactId = $this->parentContactIdFromXeroContact(
                    isset($bt['Contact']) && is_array($bt['Contact']) ? $bt['Contact'] : null
                );
                $this->appendExpenseLines(
                    $organisation,
                    $lineItems,
                    (string) $parentId,
                    $date,
                    (string) $currency,
                    $reference ? (string) $reference : null,
                    $expenseAccountIds,
                    ExpenseLine::SOURCE_SPEND,
                    $parentContactId,
                    $rows
                );
            }
        }
    }

    /**
     * @param  list<string>  $expenseAccountIds
     * @param  list<array<string, mixed>>  $rows
     */
    private function collectBillLines(
        Organisation $organisation,
        Carbon $dateFrom,
        Carbon $dateTo,
        array $expenseAccountIds,
        array &$rows,
    ): void {
        $where = 'Type=="ACCPAY"&&'.$this->xeroDateWhere($dateFrom, $dateTo);

        for ($page = 1; ; $page++) {
            $json = $this->client->get($organisation, 'Invoices', [
                'page' => $page,
                'where' => $where,
            ]);
            $list = $json['Invoices'] ?? [];
            if (count($list) === 0) {
                break;
            }
            foreach ($list as $inv) {
                $parentId = $inv['InvoiceID'] ?? null;
                if (! $parentId) {
                    continue;
                }
                $date = isset($inv['Date']) ? $this->parseXeroDate($inv['Date'])->toDateString() : $dateFrom->toDateString();
                $currency = $inv['CurrencyCode'] ?? 'USD';
                $reference = $inv['InvoiceNumber'] ?? null;
                $lineItems = $inv['LineItems'] ?? [];
                $parentContactId = $this->parentContactIdFromXeroContact(
                    isset($inv['Contact']) && is_array($inv['Contact']) ? $inv['Contact'] : null
                );
                $this->appendExpenseLines(
                    $organisation,
                    $lineItems,
                    (string) $parentId,
                    $date,
                    (string) $currency,
                    $reference ? (string) $reference : null,
                    $expenseAccountIds,
                    ExpenseLine::SOURCE_BILL,
                    $parentContactId,
                    $rows
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  list<string>  $expenseAccountIds
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendExpenseLines(
        Organisation $organisation,
        array $lineItems,
        string $parentId,
        string $date,
        string $currency,
        ?string $reference,
        array $expenseAccountIds,
        string $sourceKind,
        ?string $parentContactId,
        array &$rows,
    ): void {
        foreach ($lineItems as $item) {
            $lineId = $this->lineItemId($item);
            if (! $lineId) {
                continue;
            }
            $accountId = $item['AccountID'] ?? null;
            $normId = $accountId ? strtolower((string) $accountId) : null;
            if ($normId === null || ! in_array($normId, $expenseAccountIds, true)) {
                continue;
            }
            $rows[] = [
                'organisation_id' => $organisation->id,
                'source_kind' => $sourceKind,
                'parent_transaction_id' => $parentId,
                'line_item_id' => $lineId,
                'occurred_on' => $date,
                'line_amount' => (float) ($item['LineAmount'] ?? 0),
                'currency_code' => $currency,
                'account_id' => $accountId,
                'account_code' => $item['AccountCode'] ?? null,
                'description' => isset($item['Description']) ? Str::limit((string) $item['Description'], 2000) : null,
                'parent_reference' => $reference ? Str::limit($reference, 500) : null,
                'parent_contact_id' => $parentContactId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $contact
     */
    private function parentContactIdFromXeroContact(?array $contact): ?string
    {
        if ($contact === null) {
            return null;
        }
        $id = $contact['ContactID'] ?? null;

        return $id ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function lineItemId(array $item): ?string
    {
        $id = $item['LineItemID'] ?? $item['LineItemId'] ?? null;

        return $id ? (string) $id : null;
    }

    private function xeroDateWhere(Carbon $from, Carbon $to): string
    {
        return sprintf(
            'Date>=DateTime(%s)&&Date<=DateTime(%s)',
            $from->format('Y,m,d'),
            $to->format('Y,m,d')
        );
    }

    /**
     * Xero sometimes returns JSON dates as Microsoft-style "/Date(1768608000000+0000)/" strings.
     */
    private function parseXeroDate(mixed $value): Carbon
    {
        $value = trim(is_string($value) ? $value : (string) $value);

        if (preg_match('#^/Date\((\d+)([+-]\d+)?\)/$#', $value, $m)) {
            return Carbon::createFromTimestampMs((int) $m[1]);
        }

        return Carbon::parse($value);
    }

    /**
     * Re-fetch billable expense links from Xero and replace local rows for this organisation.
     */
    public function refreshLinkedTransactionsForOrganisation(Organisation $organisation): void
    {
        $this->replaceLinkedTransactionLines($organisation);
    }

    private function replaceLinkedTransactionLines(Organisation $organisation): void
    {
        LinkedTransactionLine::withoutGlobalScopes()->where('organisation_id', $organisation->id)->delete();

        $rows = [];
        for ($page = 1; ; $page++) {
            $json = $this->client->get($organisation, 'LinkedTransactions', ['page' => $page]);
            $list = $json['LinkedTransactions'] ?? [];
            if (count($list) === 0) {
                break;
            }
            foreach ($list as $lt) {
                $linkId = $lt['LinkedTransactionID'] ?? null;
                $st = $lt['SourceTransactionID'] ?? null;
                $sl = $lt['SourceLineItemID'] ?? null;
                if (! $linkId || ! $st || ! $sl) {
                    continue;
                }
                $contact = $lt['ContactID'] ?? null;
                $targetTx = $lt['TargetTransactionID'] ?? null;
                $targetLine = $lt['TargetLineItemID'] ?? null;
                $rows[] = [
                    'organisation_id' => $organisation->id,
                    'xero_linked_transaction_id' => (string) $linkId,
                    'source_transaction_id' => (string) $st,
                    'source_line_item_id' => (string) $sl,
                    'contact_id' => $contact !== null && $contact !== '' ? (string) $contact : null,
                    'target_transaction_id' => $targetTx !== null && $targetTx !== '' ? (string) $targetTx : null,
                    'target_line_item_id' => $targetLine !== null && $targetLine !== '' ? (string) $targetLine : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            LinkedTransactionLine::withoutGlobalScopes()->insert($chunk);
        }

        $this->reconcileLineReviewInvoiceFlagsFromLinkedTransactions($organisation);
    }

    /**
     * Set needs_invoicing (and invoice-to contact) from Xero billable links.
     * Open links (no target invoice): needs_invoicing true when a customer contact is set.
     * Allocated links (TargetTransactionID present): needs_invoicing false; contact from link when present.
     * On duplicate key, only needs_invoicing, selected_contact_id, reviewed_at, and updated_at are updated
     * so existing notes and is_reviewed are preserved.
     */
    public function reconcileLineReviewInvoiceFlagsFromLinkedTransactions(Organisation $organisation): void
    {
        $this->upsertLineReviewsForOpenBillableLinks($organisation);
        $this->upsertLineReviewsForAllocatedBillableLinks($organisation);
    }

    private function upsertLineReviewsForOpenBillableLinks(Organisation $organisation): void
    {
        $pairs = DB::table('expense_lines as el')
            ->join('linked_transaction_lines as ltl', function ($join): void {
                $join->on('ltl.organisation_id', '=', 'el.organisation_id')
                    ->on('ltl.source_transaction_id', '=', 'el.parent_transaction_id')
                    ->on('ltl.source_line_item_id', '=', 'el.line_item_id');
            })
            ->where('el.organisation_id', $organisation->id)
            ->whereNotNull('ltl.contact_id')
            ->whereNull('ltl.target_transaction_id')
            ->select([
                'el.organisation_id',
                'el.source_kind',
                'el.parent_transaction_id',
                'el.line_item_id',
                'ltl.contact_id',
            ])
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $now = now();
        $payload = [];
        foreach ($pairs as $row) {
            $payload[] = [
                'organisation_id' => (int) $row->organisation_id,
                'source_kind' => (string) $row->source_kind,
                'parent_transaction_id' => (string) $row->parent_transaction_id,
                'line_item_id' => (string) $row->line_item_id,
                'needs_invoicing' => true,
                'selected_contact_id' => (string) $row->contact_id,
                'is_reviewed' => false,
                'notes' => null,
                'user_id' => null,
                'xero_last_pushed_notes' => null,
                'xero_push_error' => null,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 250) as $chunk) {
            LineReview::withoutGlobalScopes()->upsert(
                $chunk,
                ['organisation_id', 'source_kind', 'parent_transaction_id', 'line_item_id'],
                ['needs_invoicing', 'selected_contact_id', 'reviewed_at', 'updated_at'],
            );
        }
    }

    private function upsertLineReviewsForAllocatedBillableLinks(Organisation $organisation): void
    {
        $pairs = DB::table('expense_lines as el')
            ->join('linked_transaction_lines as ltl', function ($join): void {
                $join->on('ltl.organisation_id', '=', 'el.organisation_id')
                    ->on('ltl.source_transaction_id', '=', 'el.parent_transaction_id')
                    ->on('ltl.source_line_item_id', '=', 'el.line_item_id');
            })
            ->where('el.organisation_id', $organisation->id)
            ->whereNotNull('ltl.target_transaction_id')
            ->select([
                'el.organisation_id',
                'el.source_kind',
                'el.parent_transaction_id',
                'el.line_item_id',
                'ltl.contact_id',
            ])
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            return;
        }

        $now = now();
        $payload = [];
        foreach ($pairs as $row) {
            $contactId = $row->contact_id !== null && $row->contact_id !== '' ? (string) $row->contact_id : null;
            $payload[] = [
                'organisation_id' => (int) $row->organisation_id,
                'source_kind' => (string) $row->source_kind,
                'parent_transaction_id' => (string) $row->parent_transaction_id,
                'line_item_id' => (string) $row->line_item_id,
                'needs_invoicing' => false,
                'selected_contact_id' => $contactId,
                'is_reviewed' => false,
                'notes' => null,
                'user_id' => null,
                'xero_last_pushed_notes' => null,
                'xero_push_error' => null,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 250) as $chunk) {
            LineReview::withoutGlobalScopes()->upsert(
                $chunk,
                ['organisation_id', 'source_kind', 'parent_transaction_id', 'line_item_id'],
                ['needs_invoicing', 'selected_contact_id', 'reviewed_at', 'updated_at'],
            );
        }
    }
}
