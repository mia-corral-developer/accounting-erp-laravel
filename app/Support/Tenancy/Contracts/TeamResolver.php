<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Contracts;

use Illuminate\Http\Request;

/**
 * Resolves the tenant a single boundary carries, or null when it carries none
 * (ADR-001 §5).
 *
 * Each boundary owns exactly one resolver. A resolver MUST NOT borrow another
 * boundary's signal, and MUST NOT pick a winner when signals disagree —
 * arbitration belongs to the wire-up ({@see \App\Http\Middleware\ResolveTenantContext}).
 */
interface TeamResolver
{
    public function resolve(Request $request): ?int;
}
