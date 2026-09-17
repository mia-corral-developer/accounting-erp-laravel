<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-owned data is touched without an authorised tenant
 * context (ADR-001 §2).
 *
 * The absence of a tenant context is a programming error, never a query
 * result: it must fail LOUD, not silently degrade to `where 1 = 0` or an
 * unscoped global read.
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function forAccess(string $surface = 'tenant-owned data'): self
    {
        return new self(sprintf(
            'Attempted to access %s with an UNRESOLVED tenant context. '
            .'Resolve a tenant at the boundary (resolver/middleware) or grant an '
            .'explicit central elevation (TeamContext::runCentral).',
            $surface,
        ));
    }
}
