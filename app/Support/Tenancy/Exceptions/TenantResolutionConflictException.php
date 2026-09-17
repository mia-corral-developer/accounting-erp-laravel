<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when two boundary signals disagree about which tenant applies
 * (ADR-001 §5): e.g. session selects team 10 while the route selects team 20.
 *
 * A conflict is never resolved silently — the code refuses to choose and makes
 * the disagreement explicit.
 */
final class TenantResolutionConflictException extends RuntimeException
{
    /** @param array<string, int|string|null> $signals */
    public static function between(array $signals): self
    {
        $parts = [];
        foreach ($signals as $source => $value) {
            $parts[] = sprintf('%s=%s', $source, var_export($value, true));
        }

        return new self(sprintf(
            'Conflicting tenant signals (%s). Refusing to choose — resolve the conflict explicitly.',
            implode(', ', $parts),
        ));
    }
}
