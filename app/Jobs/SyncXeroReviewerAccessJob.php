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

    public int $uniqueFor = 600;

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
