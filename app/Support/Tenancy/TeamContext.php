<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the "current team" for tenant-scoped queries.
 *
 * Resolution order:
 *  1. an explicitly set id (tests, console, queued jobs via {@see run()});
 *  2. otherwise the authenticated user's current_team_id (web requests).
 *
 * Bound as a singleton so explicit ids persist for the life of the request.
 */
final class TeamContext
{
    private ?int $teamId = null;

    private bool $explicit = false;

    public function set(?int $teamId): void
    {
        $this->teamId = $teamId;
        $this->explicit = true;
    }

    public function forget(): void
    {
        $this->teamId = null;
        $this->explicit = false;
    }

    public function id(): ?int
    {
        if ($this->explicit) {
            return $this->teamId;
        }

        $user = Auth::user();

        return $user instanceof User && $user->current_team_id !== null
            ? (int) $user->current_team_id
            : null;
    }

    public function has(): bool
    {
        return $this->id() !== null;
    }

    /**
     * Run a callback with an explicit team scoped, then restore the previous
     * context. Use this in console commands / jobs that must stay isolated.
     */
    public function run(int $teamId, callable $callback): mixed
    {
        $previousId = $this->teamId;
        $previousExplicit = $this->explicit;

        $this->set($teamId);

        try {
            return $callback();
        } finally {
            $this->teamId = $previousId;
            $this->explicit = $previousExplicit;
        }
    }
}