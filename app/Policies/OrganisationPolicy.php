<?php

namespace App\Policies;

use App\Models\Organisation;
use App\Models\User;

class OrganisationPolicy
{
    public function view(User $user, Organisation $organisation): bool
    {
        return $user->isMemberOf($organisation);
    }

    public function sync(User $user, Organisation $organisation): bool
    {
        return $user->isMemberOf($organisation);
    }

    public function invite(User $user, Organisation $organisation): bool
    {
        return $user->isOrganisationAdmin($organisation);
    }

    public function manageXeroConnection(User $user, Organisation $organisation): bool
    {
        return $user->isOrganisationAdmin($organisation);
    }

    public function updateReviews(User $user, Organisation $organisation): bool
    {
        return $user->isMemberOf($organisation);
    }
}
