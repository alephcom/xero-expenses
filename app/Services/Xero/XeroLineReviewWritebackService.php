<?php

namespace App\Services\Xero;

use App\Models\ExpenseLine;
use App\Models\LineReview;
use App\Models\Organisation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

class XeroLineReviewWritebackService
{
    public function __construct(
        private readonly XeroApiClient $client,
        private readonly XeroSyncService $syncService,
    ) {}

    /**
     * Push billable-expense link and optional history note for a line review row.
     *
     * @throws RequestException
     */
    public function push(Organisation $organisation, LineReview $review): void
    {
        $this->syncBillableExpense($organisation, $review);
        $this->syncHistoryNoteIfNeeded($organisation, $review);
        $this->syncService->refreshLinkedTransactionsForSource($organisation, $review->parent_transaction_id);
    }

    /**
     * @throws RequestException
     */
    private function syncBillableExpense(Organisation $organisation, LineReview $review): void
    {
        $typeCode = $this->xeroSourceTransactionTypeCode($review->source_kind);
        $existing = $this->findLinkedTransactionForLine(
            $organisation,
            $review->parent_transaction_id,
            $review->line_item_id,
            $typeCode,
        );

        if ($this->linkedTransactionPayloadHasTarget($existing)) {
            return;
        }

        if (! $review->needs_invoicing) {
            if ($existing !== null) {
                $linkId = (string) ($existing['LinkedTransactionID'] ?? '');
                if ($linkId !== '') {
                    $this->client->delete($organisation, 'LinkedTransactions/'.$linkId);
                }
            }

            return;
        }

        if ($review->selected_contact_id === null || $review->selected_contact_id === '') {
            return;
        }

        $contactId = (string) $review->selected_contact_id;

        if ($existing === null) {
            $this->client->post($organisation, 'LinkedTransactions', [
                'SourceTransactionID' => $review->parent_transaction_id,
                'SourceLineItemID' => $review->line_item_id,
                'ContactID' => $contactId,
            ]);

            return;
        }

        $linkId = (string) ($existing['LinkedTransactionID'] ?? '');
        $existingContact = isset($existing['ContactID']) ? (string) $existing['ContactID'] : '';

        if ($linkId === '') {
            return;
        }

        if (strcasecmp($existingContact, $contactId) === 0) {
            return;
        }

        $this->client->post($organisation, 'LinkedTransactions/'.$linkId, [
            'LinkedTransactionID' => $linkId,
            'ContactID' => $contactId,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $linkedTransaction
     */
    private function linkedTransactionPayloadHasTarget(?array $linkedTransaction): bool
    {
        if ($linkedTransaction === null) {
            return false;
        }

        $target = $linkedTransaction['TargetTransactionID'] ?? null;

        return $target !== null && $target !== '';
    }

    /**
     * @throws RequestException
     */
    private function syncHistoryNoteIfNeeded(Organisation $organisation, LineReview $review): void
    {
        $notes = $review->notes;
        if ($notes === null || trim((string) $notes) === '') {
            if ($review->xero_last_pushed_notes !== null) {
                $review->forceFill(['xero_last_pushed_notes' => null])->saveQuietly();
            }

            return;
        }

        $description = ExpenseLine::withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)
            ->where('source_kind', $review->source_kind)
            ->where('parent_transaction_id', $review->parent_transaction_id)
            ->where('line_item_id', $review->line_item_id)
            ->value('description');

        $composed = $this->composeHistoryDetails((string) $review->line_item_id, $description, (string) $notes);

        if ($review->xero_last_pushed_notes === $composed) {
            return;
        }

        $path = match ($review->source_kind) {
            ExpenseLine::SOURCE_SPEND => 'BankTransactions/'.$review->parent_transaction_id.'/History',
            ExpenseLine::SOURCE_BILL => 'Invoices/'.$review->parent_transaction_id.'/History',
            default => throw new \InvalidArgumentException('Unsupported source kind: '.$review->source_kind),
        };

        $this->client->put($organisation, $path, [
            'HistoryRecords' => [
                ['Details' => $composed],
            ],
        ]);

        $review->forceFill(['xero_last_pushed_notes' => $composed])->saveQuietly();
    }

    private function composeHistoryDetails(string $lineItemId, ?string $expenseDescription, string $notes): string
    {
        $tail = Str::lower(mb_substr($lineItemId, -8));
        $prefix = '[Line …'.$tail.'] ';
        if ($expenseDescription !== null && trim($expenseDescription) !== '') {
            $prefix .= '('.Str::limit(trim($expenseDescription), 60).') ';
        }

        return Str::limit($prefix.trim($notes), 2000);
    }

    private function xeroSourceTransactionTypeCode(string $sourceKind): string
    {
        return match ($sourceKind) {
            ExpenseLine::SOURCE_SPEND => 'SPEND',
            ExpenseLine::SOURCE_BILL => 'ACCPAY',
            default => throw new \InvalidArgumentException('Unsupported source kind: '.$sourceKind),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLinkedTransactionsForSource(Organisation $organisation, string $sourceTransactionId): array
    {
        $all = [];
        for ($page = 1; ; $page++) {
            $json = $this->client->get($organisation, 'LinkedTransactions', [
                'SourceTransactionID' => $sourceTransactionId,
                'page' => $page,
            ]);
            $list = $json['LinkedTransactions'] ?? [];
            if (count($list) === 0) {
                break;
            }
            foreach ($list as $row) {
                $all[] = $row;
            }
            if (count($list) < 100) {
                break;
            }
        }

        return $all;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLinkedTransactionForLine(
        Organisation $organisation,
        string $parentTransactionId,
        string $lineItemId,
        string $sourceTransactionTypeCode,
    ): ?array {
        $rows = $this->fetchLinkedTransactionsForSource($organisation, $parentTransactionId);

        foreach ($rows as $lt) {
            $sl = $lt['SourceLineItemID'] ?? null;
            $code = $lt['SourceTransactionTypeCode'] ?? null;
            if ($sl === null || $code === null) {
                continue;
            }
            if (strcasecmp((string) $sl, $lineItemId) !== 0) {
                continue;
            }
            if (strcasecmp((string) $code, $sourceTransactionTypeCode) !== 0) {
                continue;
            }

            return $lt;
        }

        return null;
    }
}
