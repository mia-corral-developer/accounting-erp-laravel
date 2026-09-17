<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * The active tenancy enforcement mode, resolved once and injected where the
 * decision is made ({@see TeamScope}). Kept as a tiny service so tests can pin
 * a mode without touching config, and so the mode is read in exactly one place.
 */
final class TenancyEnforcement
{
    private TenancyEnforcementMode $mode;

    public function __construct(?TenancyEnforcementMode $mode = null)
    {
        $this->mode = $mode ?? TenancyEnforcementMode::fromConfig();
    }

    public function mode(): TenancyEnforcementMode
    {
        return $this->mode;
    }

    public function isEnforcing(): bool
    {
        return $this->mode->isEnforcing();
    }

    public function isObserving(): bool
    {
        return $this->mode->isObserving();
    }
}
