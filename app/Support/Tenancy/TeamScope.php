<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Global scope that keeps team-scoped models inside the caller's team.
 *
 * - A current team is resolvable  -> filter `where team_id = <current>`.
 * - No team resolved               -> behaviour depends on the enforcement mode
 *   (ADR-001 §3.3, config `tenancy.enforcement`):
 *     · LEGACY  — fail CLOSED (`where 1 = 0`) for authenticated callers, so an
 *                 authenticated request can never read another team's rows;
 *                 unscoped for console (seeders/migrations/queued jobs) so
 *                 provisioning keeps working.
 *     · OBSERVE — same as LEGACY, plus a warning log recording the model that
 *                 ran without a resolved context (telemetry; nothing breaks).
 *     · ENFORCE — throw {@see MissingTenantContextException}. Fail LOUD instead
 *                 of degrading to a silent empty/global read.
 *
 * The default mode is LEGACY, so flipping enforcement is a deliberate act.
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

        $mode = app(TenancyEnforcement::class)->mode();

        if ($mode === TenancyEnforcementMode::ENFORCE) {
            throw MissingTenantContextException::forAccess($model::class);
        }

        if ($mode === TenancyEnforcementMode::OBSERVE) {
            Log::warning('Tenant-owned query without resolved context', [
                'model' => $model::class,
                'table' => $model->getTable(),
                'authenticated' => Auth::check(),
                'user_id' => Auth::id(),
                'mode' => $mode->value,
            ]);
        }

        if (Auth::check()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
