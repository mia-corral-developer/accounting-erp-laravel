<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy enforcement kill-switch (ADR-001 §3.3)
    |--------------------------------------------------------------------------
    |
    | Controls how a tenant-owned query behaves when NO tenant context is
    | resolvable. Aliases accepted (first term is canonical):
    |
    |   off       (legacy)   — current behaviour: filter when resolvable, else
    |                          fail closed (`where 1 = 0`) for authenticated
    |                          callers, unscoped for console/seeders.
    |   per_model (observe)  — identical behaviour, PLUS a warning log for every
    |                          tenant-owned query that ran without a resolved
    |                          context. Telemetry only: nothing breaks. This is
    |                          how we size the blast radius before enforcing.
    |   strict    (enforce)  — a tenant-owned query with no resolved context
    |                          throws MissingTenantContextException. Fail LOUD.
    |
    | Default is `off`: enforcement is turned on deliberately, per environment,
    | only once the boundary resolvers are proven in production (OBSERVE first).
    |
    */

    'enforcement' => env('TENANCY_ENFORCEMENT', 'off'),

    /*
    |--------------------------------------------------------------------------
    | Known-undeclared tenant models (isolation canary baseline)
    |--------------------------------------------------------------------------
    |
    | Path to the list of tenant-owned models that are, today, neither scoped
    | nor explicitly declared central/shared. The canary only fails on NEW
    | offenders, so existing debt is frozen rather than blocking every deploy.
    | Shrink this file to empty as BelongsToCurrentTeam + RLS (Fase 3/4) land.
    |
    */

    'unprotected_baseline' => base_path('tests/Feature/Tenancy/unprotected-tenant-models.php'),

];
