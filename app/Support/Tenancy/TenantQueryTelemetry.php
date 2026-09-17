<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Query-level tenancy telemetry (ADR-001 §3.3, OBSERVE mode).
 *
 * The scoped {@see TeamScope} only sees models that opted into
 * `BelongsToCurrentTeam` — it is blind to the models that are tenant-owned by
 * schema but not (yet) scoped. This listener sits on the connection instead, so
 * it sees EVERY statement and can flag the ones that touch a table carrying a
 * `team_id` column while no tenant context has been resolved.
 *
 * Why it matters: it turns "210 unprotected tables" into a ranked, empirical
 * list of *which* of them are actually exercised without a tenant — the input
 * for prioritising before RLS (Fase 3) closes them in the engine.
 *
 * It is a pure observer: registered only when `tenancy.enforcement` is OBSERVE,
 * it never changes behaviour and never throws. In LEGACY / ENFORCE it does
 * nothing.
 */
final class TenantQueryTelemetry
{
    /** @var array<string, true>|null lowercased set of tenant-owned tables, or null until first use */
    private ?array $tenantTables = null;

    /** @var array<string, true> distinct queries already reported in this process */
    private array $reported = [];

    /** Guards against re-entrancy (a log/observe write must not observe itself). */
    private bool $inspecting = false;

    public function __construct(
        private readonly TenancyEnforcement $enforcement,
        private readonly TenantModelRegistry $registry,
    ) {}

    /** Attach the observer to the DB connection, if (and only if) OBSERVE is on. */
    public function register(): void
    {
        if (! $this->enforcement->isObserving()) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $this->inspect($query);
        });
    }

    public function inspect(QueryExecuted $query): void
    {
        if ($this->inspecting) {
            return;
        }

        // Already resolved to a tenant (or central)? Nothing to measure.
        if (app(TeamContext::class)->id() !== null) {
            return;
        }

        $tables = $this->tablesIn($query->sql);

        if ($tables === []) {
            return;
        }

        $hits = array_values(array_intersect($tables, array_keys($this->tenantTables())));

        if ($hits === []) {
            return;
        }

        // Report each distinct statement once per process — enough signal,
        // no flooding under load.
        $key = md5($query->sql);
        if (isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;

        $this->inspecting = true;

        try {
            Log::warning('Tenant-owned query executed without resolved context', [
                'tables' => $hits,
                'connection' => $query->connectionName,
                'sql' => Str::limit($query->sql, 500),
                'authenticated' => Auth::check(),
                'user_id' => Auth::id(),
                'mode' => $this->enforcement->mode()->value,
            ]);
        } finally {
            $this->inspecting = false;
        }
    }

    /**
     * Table names referenced by a statement (SELECT/INSERT/UPDATE/DELETE),
     * lowercased, schema-qualified names reduced to the table part.
     *
     * @return list<string>
     */
    public function tablesIn(string $sql): array
    {
        if (! preg_match_all(
            '/\b(?:from|join|into|update)\s+(?:[`"\[]?[a-zA-Z0-9_]+[`"\]]?\.)?[`"\[]?([a-zA-Z0-9_]+)[`"\]]?/i',
            $sql,
            $matches,
        )) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /** @return array<string, true> */
    private function tenantTables(): array
    {
        return $this->tenantTables ??= array_change_key_case($this->registry->tenantTables(), CASE_LOWER);
    }
}
