<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that keeps team-scoped models inside the caller's team.
 *
 * - A current team is resolvable  -> filter `where team_id = <current>`.
 * - No team, but a user is signed in -> fail CLOSED (`where 1 = 0`) so an
 *   authenticated request can never read another team's rows.
 * - No auth at all (console, queued jobs, migrations) -> leave unscoped, so
 *   provisioning / seeders / maintenance keep working. Opt into isolation with
 *   TeamContext::run() when a job does need it.
 */
class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $teamId = app(TeamContext::class)->id();
        $column = $model->qualifyColumn('team_id');

        if ($teamId !== null) {
            $builder->where($column, $teamId);

            return;
        }

        if (Auth::check()) {
            $builder->whereRaw('1 = 0');
        }
    }
}