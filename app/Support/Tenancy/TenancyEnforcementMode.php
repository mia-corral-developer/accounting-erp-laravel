<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * How tenant-owned access is enforced at the application layer (ADR-001 §3.3).
 *
 * LEGACY  — current behaviour: filter when a tenant is resolvable, fail closed
 *           (`where 1 = 0`) for authenticated callers, unscoped for console.
 * OBSERVE — same behaviour, but a tenant-scoped query that runs without a
 *           resolved context is logged. Telemetry only — nothing changes for
 *           the caller. This is how we measure the blast radius before flipping.
 * ENFORCE — a tenant-owned query with no resolved tenant throws
 *           {@see Exceptions\MissingTenantContextException} instead of
 *           degrading silently.
 *
 * The default is LEGACY: enforcement is a deliberate, per-environment switch,
 * turned on only once the boundary resolvers are proven (ADR-001 §3.3).
 */
enum TenancyEnforcementMode: string
{
    case LEGACY = 'legacy';
    case OBSERVE = 'observe';
    case ENFORCE = 'enforce';

    /**
     * Map the `TENANCY_ENFORCEMENT` kill-switch (or an explicit value) to a
     * mode. Unknown values degrade to the safe default (LEGACY).
     */
    public static function fromConfig(?string $value = null): self
    {
        $value ??= (string) config('tenancy.enforcement', 'off');

        return match (strtolower(trim($value))) {
            'strict', 'enforce' => self::ENFORCE,
            'per_model', 'observe' => self::OBSERVE,
            default => self::LEGACY,
        };
    }

    public function isEnforcing(): bool
    {
        return $this === self::ENFORCE;
    }

    public function isObserving(): bool
    {
        return $this === self::OBSERVE;
    }
}
