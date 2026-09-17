<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Resolvers;

use App\Models\User;
use App\Support\Tenancy\Contracts\TeamResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The authenticated-user boundary: the team the signed-in user has selected
 * (`current_team_id`). This is the implicit signal — present on every
 * authenticated request that is not pinned to a tenant by the URL.
 */
final class AuthenticatedUserTeamResolver implements TeamResolver
{
    public function resolve(Request $request): ?int
    {
        $user = Auth::user();

        return $user instanceof User && $user->current_team_id !== null
            ? (int) $user->current_team_id
            : null;
    }
}
