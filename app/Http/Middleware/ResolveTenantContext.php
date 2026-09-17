<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\Exceptions\TenantResolutionConflictException;
use App\Support\Tenancy\Resolvers\AuthenticatedUserTeamResolver;
use App\Support\Tenancy\Resolvers\RouteTenantTeamResolver;
use App\Support\Tenancy\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant context at the HTTP boundary (ADR-001 §5).
 *
 * Runs BEFORE route-model binding (`SubstituteBindings`) so bound models are
 * resolved under the correct tenant. Precedence: an explicit route `{tenant}`
 * wins over the authenticated user's current team.
 *
 * Conflict rule: two signals that disagree are NEVER resolved silently. The one
 * exception is Filament, which owns its own tenancy and legitimately switches
 * tenants through the URL — so its route tenant is authoritative and never
 * "conflicts" with the session. Everywhere else, a route tenant that disagrees
 * with the session raises {@see TenantResolutionConflictException}.
 */
final class ResolveTenantContext
{
    public function __construct(
        private readonly TeamContext $context,
        private readonly RouteTenantTeamResolver $routeResolver,
        private readonly AuthenticatedUserTeamResolver $userResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeTeam = $this->routeResolver->resolve($request);
        $userTeam = $this->userResolver->resolve($request);
        $isFilament = str_starts_with((string) $request->route()?->getName(), 'filament.');

        if (! $isFilament && $routeTeam !== null && $userTeam !== null && $routeTeam !== $userTeam) {
            throw TenantResolutionConflictException::between([
                'route' => $routeTeam,
                'session' => $userTeam,
            ]);
        }

        $teamId = $routeTeam ?? $userTeam;

        if ($teamId !== null) {
            $this->context->activateTenant($teamId);
        }

        return $next($request);
    }
}
