<?php

namespace App\Console\Commands;

use App\Jobs\SyncXeroOrganisationJob;
use App\Models\Organisation;
use App\Services\Xero\XeroSyncService;
use App\Support\OrganisationContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncXeroOrganisationCommand extends Command
{
    protected $signature = 'xero:sync {organisation : Organisation public_id (ULID) or legacy slug} {--from=} {--to=} {--queue : Dispatch to the queue} {--acting-user= : User ID performing this sync (must be a member of the organisation)}';

    protected $description = 'Sync Xero accounts, contacts, SPEND and ACCPAY expense lines, and linked transactions.';

    public function handle(XeroSyncService $sync): int
    {
        $key = $this->argument('organisation');
        $organisation = Organisation::where('public_id', $key)
            ->orWhere('slug', $key)
            ->firstOrFail();
        $defaults = SyncXeroOrganisationJob::defaultRange();
        $from = $this->option('from') ?? $defaults['dateFrom'];
        $to = $this->option('to') ?? $defaults['dateTo'];

        $actingUserId = (int) ($this->option('acting-user') ?? 0);
        if ($actingUserId < 1) {
            $this->components->error('The --acting-user option is required: pass a user ID that belongs to this organisation (see users table).');

            return self::FAILURE;
        }

        if (! $organisation->users()->where('users.id', $actingUserId)->exists()) {
            $this->components->error('That user is not a member of this organisation.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            SyncXeroOrganisationJob::dispatch($organisation->id, $from, $to, $actingUserId);
            $this->components->info('Sync queued.');

            return self::SUCCESS;
        }

        OrganisationContext::set($organisation->id);
        $sync->sync($organisation, Carbon::parse($from), Carbon::parse($to));
        $this->components->info('Sync completed.');

        return self::SUCCESS;
    }
}
