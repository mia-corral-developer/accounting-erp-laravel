<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use App\Support\Tenancy\TeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in tenant guardrail for models that carry a `team_id`.
 *
 * Unlike the legacy {@see \App\Traits\IsTenantModel} (which only adds the
 * `team()` relation), this trait registers a global {@see TeamScope} so reads
 * are automatically restricted to the current team — closing the "forgot a
 * where team_id" cross-tenant leak class. Escape hatch:
 * `Model::withoutTeamScope()`.
 *
 * Rollout: replace `use IsTenantModel;` with `use BelongsToCurrentTeam;` on each
 * model to protect. Keep models used heavily in console/seeders on the old trait
 * until their contexts are reviewed.
 */
trait BelongsToCurrentTeam
{
    public static function bootBelongsToCurrentTeam(): void
    {
        static::addGlobalScope(new TeamScope());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeWithoutTeamScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TeamScope::class);
    }
}