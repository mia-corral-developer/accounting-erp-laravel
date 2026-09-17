<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Authoritative tenant context for the current execution (ADR-001).
 *
 * Lifetime is **scoped** (per request/job), never singleton, so a context can
 * never leak across request or queue lifecycles. Scoped lifetime alone is NOT
 * an isolation control: isolation begins only once an authorised boundary
 * resolver has transitioned the state out of UNRESOLVED.
 *
 * Three states (ADR-001 §3.1):
 *   UNRESOLVED — nothing has authorised a tenant yet.
 *   TENANT(id) — normal team-scoped execution.
 *   CENTRAL    — explicit, auditable platform-wide elevation.
 *
 * This class only *holds* the decision and offers the sanctioned escapes; the
 * *initialisation* is the job of the boundary resolvers (Fase 2), enabled at
 * the boundary. It must NOT be used to enable enforcement before those
 * resolvers exist and are tested (ADR-001 §3.3).
 */
final class TeamContext
{
    private TenancyState $state = TenancyState::UNRESOLVED;

    private ?int $tenantId = null;

    // ---- state queries ---------------------------------------------------

    public function state(): TenancyState
    {
        return $this->state;
    }

    public function isResolved(): bool
    {
        return $this->state !== TenancyState::UNRESOLVED;
    }

    public function isTenant(): bool
    {
        return $this->state === TenancyState::TENANT;
    }

    public function isCentral(): bool
    {
        return $this->state === TenancyState::CENTRAL;
    }

    /** The tenant id, or null when not in the TENANT state. */
    public function tenantId(): ?int
    {
        return $this->state === TenancyState::TENANT ? $this->tenantId : null;
    }

    // ---- transitions -----------------------------------------------------

    /** Authorised transition to TENANT(id). */
    public function activateTenant(int $teamId): void
    {
        $this->state = TenancyState::TENANT;
        $this->tenantId = $teamId;
    }

    /**
     * Legacy setter kept for existing callers/tests. A non-null id activates
     * TENANT; null returns to UNRESOLVED.
     */
    public function set(?int $teamId): void
    {
        if ($teamId === null) {
            $this->forget();

            return;
        }

        $this->activateTenant($teamId);
    }

    /** Return to UNRESOLVED. */
    public function forget(): void
    {
        $this->state = TenancyState::UNRESOLVED;
        $this->tenantId = null;
    }

    // ---- legacy read (kept for TeamScope while enforcement rolls out) ----

    /**
     * The effective team id: the explicitly activated tenant, otherwise the
     * authenticated user's current_team_id (web requests).
     *
     * Kept for backward-compatibility with {@see TeamScope}. Prefer
     * {@see requireTenant()} on tenant-owned access that must be fail-closed.
     */
    public function id(): ?int
    {
        $tenantId = $this->tenantId();

        if ($tenantId !== null) {
            return $tenantId;
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

    // ---- enforcement -----------------------------------------------------

    /**
     * The tenant id, or a LOUD failure. Use on any tenant-owned access that
     * must not run without an authorised context (ADR-001 §2).
     *
     * @throws MissingTenantContextException
     */
    public function requireTenant(string $surface = 'tenant-owned data'): int
    {
        $teamId = $this->id();

        if ($teamId === null) {
            throw MissingTenantContextException::forAccess($surface);
        }

        return $teamId;
    }

    // ---- sanctioned scopes ----------------------------------------------

    /** Run a callback as TENANT(teamId), restoring the previous state. */
    public function runTenant(int $teamId, callable $callback): mixed
    {
        return $this->withState(TenancyState::TENANT, $teamId, $callback);
    }

    /**
     * Run a callback with an explicit central elevation (ADR-001 §6). Every
     * elevation is logged with actor, reason, callsite and timestamp.
     */
    public function runCentral(string $reason, callable $callback): mixed
    {
        Log::warning('Central tenancy elevation', [
            'reason' => $reason,
            'actor' => Auth::id(),
            'callsite' => $this->callsite(),
            'at' => now()->toIso8601String(),
        ]);

        return $this->withState(TenancyState::CENTRAL, null, $callback);
    }

    /** Legacy alias for {@see runTenant()}. */
    public function run(int $teamId, callable $callback): mixed
    {
        return $this->runTenant($teamId, $callback);
    }

    private function withState(TenancyState $state, ?int $tenantId, callable $callback): mixed
    {
        $previousState = $this->state;
        $previousTenantId = $this->tenantId;

        $this->state = $state;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->state = $previousState;
            $this->tenantId = $previousTenantId;
        }
    }

    private function callsite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file)) {
                return $file.':'.($frame['line'] ?? '0');
            }
        }

        return 'unknown';
    }
}
