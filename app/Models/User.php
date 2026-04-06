<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'xero_sub'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organisations(): BelongsToMany
    {
        return $this->belongsToMany(Organisation::class)->withPivot('role');
    }

    public function organisationRole(Organisation $organisation): ?OrganisationRole
    {
        $row = $this->organisations()->where('organisations.id', $organisation->id)->first();

        return OrganisationRole::tryFrom((string) ($row?->pivot?->role ?? '')) ?: null;
    }

    public function isOrganisationAdmin(Organisation $organisation): bool
    {
        return $this->organisationRole($organisation) === OrganisationRole::Admin;
    }

    public function isMemberOf(Organisation $organisation): bool
    {
        return $this->organisations()->where('organisations.id', $organisation->id)->exists();
    }

    public function canConnectXero(): bool
    {
        return $this->organisations()->doesntExist()
            || $this->organisations()->wherePivot('role', OrganisationRole::Admin->value)->exists();
    }
}
