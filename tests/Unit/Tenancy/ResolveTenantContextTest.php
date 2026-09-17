<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;
use App\Support\Tenancy\Exceptions\TenantResolutionConflictException;
use App\Support\Tenancy\Resolvers\AuthenticatedUserTeamResolver;
use App\Support\Tenancy\Resolvers\RouteTenantTeamResolver;
use App\Support\Tenancy\TeamContext;
use App\Support\Tenancy\TenancyState;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;

function tenantMiddleware(TeamContext $ctx): ResolveTenantContext
{
    return new ResolveTenantContext($ctx, new RouteTenantTeamResolver(), new AuthenticatedUserTeamResolver());
}

function requestWithTenant(?string $tenant, ?string $routeName = null): Request
{
    $request = Request::create('/x');

    if ($tenant !== null) {
        $route = new Route('GET', '/x', fn () => 'ok');
        $route->bind($request);
        $route->setParameter('tenant', $tenant);
        if ($routeName !== null) {
            $route->name($routeName);
        }
        $request->setRouteResolver(fn () => $route);
    }

    return $request;
}

function actingUser(?int $teamId): void
{
    if ($teamId === null) {
        return;
    }

    $user = new User();
    $user->id = 1;
    $user->current_team_id = $teamId;
    Auth::setUser($user);
}

it('activates TENANT from an explicit route {tenant} parameter', function () {
    $ctx = new TeamContext();
    $request = requestWithTenant('7');

    tenantMiddleware($ctx)->handle($request, fn () => response('ok'));

    expect($ctx->state())->toBe(TenancyState::TENANT)
        ->and($ctx->tenantId())->toBe(7);
});

it('activates TENANT from the authenticated user when the route has no tenant', function () {
    actingUser(9);
    $ctx = new TeamContext();
    $request = requestWithTenant(null);

    tenantMiddleware($ctx)->handle($request, fn () => response('ok'));

    expect($ctx->state())->toBe(TenancyState::TENANT)
        ->and($ctx->tenantId())->toBe(9);
});

it('leaves the context UNRESOLVED when no boundary carries a tenant', function () {
    $ctx = new TeamContext();

    tenantMiddleware($ctx)->handle(requestWithTenant(null), fn () => response('ok'));

    expect($ctx->state())->toBe(TenancyState::UNRESOLVED);
});

it('never raises a conflict for Filament, which switches tenants via the URL', function () {
    actingUser(9);
    $ctx = new TeamContext();
    $request = requestWithTenant('7', 'filament.admin.pages.dashboard');

    tenantMiddleware($ctx)->handle($request, fn () => response('ok'));

    expect($ctx->tenantId())->toBe(7);
});

it('raises a LOUD conflict when a non-Filament route tenant disagrees with the session', function () {
    actingUser(9);
    $ctx = new TeamContext();
    $request = requestWithTenant('7', 'invoices.show');

    tenantMiddleware($ctx)->handle($request, fn () => response('ok'));
})->throws(TenantResolutionConflictException::class);
