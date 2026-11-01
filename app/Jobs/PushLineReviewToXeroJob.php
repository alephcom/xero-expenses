<?php

namespace App\Jobs;

use App\Jobs\Middleware\EnsureMinimumDuration;
use App\Jobs\Middleware\XeroOrganisationLock;
use App\Models\LineReview;
use App\Models\Organisation;
use App\Services\Xero\XeroLineReviewWritebackService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PushLineReviewToXeroJob implements ShouldQueue
{
    use Queueable;

    /** Keep below the queue connection's retry_after so a running push is never picked up twice. */
    public int $timeout = 120;

    /** Releases while another Xero job holds the organisation lock are fine; a real error is not retried. */
    public int $maxExceptions = 1;

    public function __construct(
        public int $organisationId,
        public string $sourceKind,
        public string $parentTransactionId,
        public string $lineItemId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // One Xero job per organisation at a time, shared with SyncXeroOrganisationJob: concurrent pushes
        // for the same line could create duplicate billable links, and both a push and a sync rewrite
        // the organisation's local link mirror.
        return [
            XeroOrganisationLock::for($this->organisationId, $this->timeout),
            new EnsureMinimumDuration,
        ];
    }

    /**
     * Overlap releases count as attempts, so bound retries by time rather than a fixed count.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(XeroLineReviewWritebackService $writeback): void
    {
        $organisation = Organisation::query()->find($this->organisationId);
        if ($organisation === null) {
            return;
        }

        $review = LineReview::withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->where('source_kind', $this->sourceKind)
            ->where('parent_transaction_id', $this->parentTransactionId)
            ->where('line_item_id', $this->lineItemId)
            ->first();

        if ($review === null) {
            return;
        }

        try {
            $writeback->push($organisation, $review);
            $review->refresh();
            $review->forceFill(['xero_push_error' => null])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Xero line review write-back failed', [
                'organisation_id' => $this->organisationId,
                'source_kind' => $this->sourceKind,
                'parent_transaction_id' => $this->parentTransactionId,
                'line_item_id' => $this->lineItemId,
                'exception' => $e,
            ]);
            $review->refresh();
            $review->forceFill(['xero_push_error' => Str::limit($e->getMessage(), 2000)])->saveQuietly();
        }
    }
}
