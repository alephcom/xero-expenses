<?php

namespace App\Services\Xero;

use App\Enums\OrganisationMembershipSource;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\OrganisationAccessExclusion;
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

    /**
     * Grant reviewer access to linked organisations where Xero lists the user with an allowed role, and
     * revoke access this service granted earlier once Xero no longer does. Memberships from invitations,
     * Connect Xero, or admin changes are left alone, and organisations an admin removed the user from
     * are skipped. A failed Xero request never revokes access.
     */
    public function syncReviewerAccessFromXero(User $user): void
    {
        if (! filter_var(config('services.xero.sso_auto_reviewer_access', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $emailLower = Str::lower($user->email);

        /** @var Collection<int, string> $membershipSources organisation id => pivot source */
        $membershipSources = $user->organisations()->get()
            ->mapWithKeys(fn (Organisation $o): array => [$o->id => (string) $o->pivot->source]);

        /** @var Collection<int, int> $excludedOrgIds */
        $excludedOrgIds = OrganisationAccessExclusion::query()->where('user_id', $user->id)->pluck('organisation_id');

        foreach (Organisation::query()->whereHas('xeroConnection')->orderBy('id')->cursor() as $organisation) {
            $source = $membershipSources->get($organisation->id);
            $isMember = $source !== null;

            if ($isMember && $source !== OrganisationMembershipSource::XeroSso->value) {
                continue;
            }

            if (! $isMember && $excludedOrgIds->contains($organisation->id)) {
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

            $users = $payload['Users'] ?? null;
            if (! is_array($users)) {
                continue;
            }

            $grantsAccess = $this->xeroUsersGrantAccess($users, $emailLower);

            if ($grantsAccess && ! $isMember) {
                $organisation->users()->attach($user->id, [
                    'role' => OrganisationRole::Reviewer->value,
                    'source' => OrganisationMembershipSource::XeroSso->value,
                ]);
            } elseif (! $grantsAccess && $isMember) {
                $organisation->users()->detach($user->id);
                Log::info('xero_sso_reviewer_sync.access_revoked', [
                    'organisation_id' => $organisation->id,
                    'user_id' => $user->id,
                ]);
            }
        }
    }

    /**
     * @param  array<mixed>  $users
     */
    private function xeroUsersGrantAccess(array $users, string $emailLower): bool
    {
        foreach ($users as $row) {
            if (! is_array($row)) {
                continue;
            }

            $addr = $row['EmailAddress'] ?? '';
            if (! is_string($addr) || Str::lower($addr) !== $emailLower) {
                continue;
            }

            $role = strtoupper((string) ($row['OrganisationRole'] ?? ''));

            return in_array($role, self::ALLOWED_XERO_ORG_ROLES, true);
        }

        return false;
    }
}
