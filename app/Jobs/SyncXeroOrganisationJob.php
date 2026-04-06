<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Xero\XeroSyncService;
use App\Support\OrganisationContext;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued Xero sync for a single organisation.
 *
 * The initiating user id is required so every run is attributed to a real account and
 * handle() can verify that user is a member of the organisation before calling Xero.
 * Do not dispatch with arbitrary org/user pairs from untrusted input: use the
 * authenticated user id from HTTP, or xero:sync --acting-user from the CLI.
 */
class SyncXeroOrganisationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $organisationId,
        public string $dateFrom,
        public string $dateTo,
        public int $initiatedByUserId,
        public ?int $syncRunId = null,
    ) {}

    /**
     * @return array{dateFrom: string, dateTo: string}
     */
    public static function defaultRange(): array
    {
        return [
            'dateFrom' => now()->subDays(90)->toDateString(),
            'dateTo' => now()->toDateString(),
        ];
    }

    public function handle(XeroSyncService $sync): void
    {
        $organisation = Organisation::findOrFail($this->organisationId);
        $user = User::findOrFail($this->initiatedByUserId);

        Gate::forUser($user)->authorize('sync', $organisation);

        OrganisationContext::set($organisation->id);

        $sync->sync(
            $organisation,
            Carbon::parse($this->dateFrom),
            Carbon::parse($this->dateTo),
            $this->syncRunId,
        );
    }

    public function failed(?Throwable $e): void
    {
        if ($this->syncRunId === null) {
            return;
        }

        $run = SyncRun::withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->whereKey($this->syncRunId)
            ->first();

        if ($run === null) {
            return;
        }

        if (! in_array($run->status, [SyncRun::STATUS_QUEUED, SyncRun::STATUS_RUNNING], true)) {
            return;
        }

        if ($e !== null) {
            Log::error('SyncXeroOrganisationJob failed', [
                'organisation_id' => $this->organisationId,
                'sync_run_id' => $this->syncRunId,
                'exception' => $e,
            ]);
        }

        $message = match (true) {
            $e === null => __('Sync failed.'),
            config('app.debug') => $e->getMessage(),
            default => __('Sync failed. Please try again or contact support.'),
        };

        $run->update([
            'status' => SyncRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => Str::limit($message, 60000),
        ]);
    }
}
