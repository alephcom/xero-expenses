<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Xero\XeroSsoOrganisationAccessService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Grants reviewer access to Xero-connected organisations where the user is a Xero user.
 *
 * Queued after Xero SSO sign-in because it calls the Xero Users API once per connected
 * organisation, which is too slow to run inside the login request.
 */
class SyncXeroReviewerAccessJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One Users call per connected organisation, each of which may wait out a 429, so the
     * worker default (60s) is too short. Keep below the queue connection's retry_after.
     */
    public int $timeout = 600;

    public int $tries = 1;

    /** Outlives the timeout only briefly, so a killed run doesn't block the next sign-in for long. */
    public int $uniqueFor = 660;

    public function __construct(
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(XeroSsoOrganisationAccessService $access): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $access->syncReviewerAccessFromXero($user);
    }
}
