<?php

namespace App\Http\Middleware;

use App\Models\Organisation;
use App\Support\OrganisationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganisationMember
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Organisation $organisation */
        $organisation = $request->route('organisation');

        abort_unless($request->user()->can('view', $organisation), 403);

        OrganisationContext::set($organisation->id);
        $request->attributes->set('currentOrganisation', $organisation);

        return $next($request);
    }
}
