<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Tenancy state of the current execution (ADR-001 §3.1).
 *
 *  - UNRESOLVED — no authorised boundary has selected a tenant yet. Tenant-owned
 *                 access in this state is a programming error, not a query result.
 *  - TENANT(id) — a normal, team-scoped execution.
 *  - CENTRAL    — an explicit, exceptional and auditable platform-wide elevation.
 */
enum TenancyState: string
{
    case UNRESOLVED = 'unresolved';
    case TENANT = 'tenant';
    case CENTRAL = 'central';
}
