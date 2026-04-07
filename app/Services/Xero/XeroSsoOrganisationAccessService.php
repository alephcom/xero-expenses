<?php

namespace App\Services\Xero;

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class XeroSsoOrganisationAccessService
{
    /** @var list<string> */
    private const ALLOWED_XERO_ORG_ROLES = [
        'STANDARD',
        'FINANCIALADVISER',
        'MANAGEDCLIENT',
    ];

    public function __construct(
        private readonly XeroApiClient $client,
    ) {}

    public function syncReviewerAccessFromXero(User $user): void
    {
        if (! filter_var(config('services.xero.sso_auto_reviewer_access', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $emailLower = Str::lower($user->email);

        /** @var Collection<int, int> $memberOrgIds */
        $memberOrgIds = $user->organisations()->pluck('id');

        foreach (Organisation::query()->whereHas('xeroConnection')->orderBy('id')->cursor() as $organisation) {
            if ($memberOrgIds->contains($organisation->id)) {
                continue;
            }

            try {
                $payload = $this->client->get($organisation, 'Users');
            } catch (Throwable $e) {
                Log::warning('xero_sso_reviewer_sync.users_request_failed', [
                    'organisation_id' => $organisation->id,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $users = $payload['Users'] ?? [];
            if (! is_array($users)) {
                continue;
            }

            foreach ($users as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $addr = $row['EmailAddress'] ?? '';
                if (! is_string($addr) || Str::lower($addr) !== $emailLower) {
                    continue;
                }

                $role = strtoupper((string) ($row['OrganisationRole'] ?? ''));
                if (! in_array($role, self::ALLOWED_XERO_ORG_ROLES, true)) {
                    break;
                }

                $organisation->users()->syncWithoutDetaching([
                    $user->id => ['role' => OrganisationRole::Reviewer->value],
                ]);
                $memberOrgIds->push($organisation->id);

                break;
            }
        }
    }
}
