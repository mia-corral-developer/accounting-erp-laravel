<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Resolvers;

use App\Support\Tenancy\Contracts\TeamResolver;
use Illuminate\Http\Request;

/**
 * The route boundary: an explicit `{tenant}` route parameter — Filament panel
 * URLs (`/admin/{tenant}/…`) and tenant-scoped routes.
 *
 * This is the strongest signal: it is what the URL literally asks for. It runs
 * before route-model binding, so the parameter is usually still the raw string;
 * a pre-bound model is handled too.
 */
final class RouteTenantTeamResolver implements TeamResolver
{
    public function resolve(Request $request): ?int
    {
        $tenant = $request->route('tenant');

        if ($tenant === null) {
            return null;
        }

        if (is_object($tenant)) {
            $tenant = $tenant->getRouteKey();
        }

        return is_numeric($tenant) ? (int) $tenant : null;
    }
}
