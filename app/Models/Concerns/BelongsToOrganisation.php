<?php

namespace App\Models\Concerns;

use App\Support\OrganisationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait BelongsToOrganisation
{
    protected static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope('organisation', function (Builder $builder): void {
            $id = OrganisationContext::id();
            if ($id !== null) {
                $builder->where($builder->getModel()->getTable().'.organisation_id', $id);
            }
        });
    }
}
