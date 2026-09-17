<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Concerns;

/**
 * Declarative marker: this model has a `team_id` column but is *central* data —
 * platform-wide rows that are legitimately visible across every team (e.g. a
 * shared catalogue or a global lookup table). It is intentionally NOT scoped.
 *
 * Declaring it explicitly is what separates a reviewed central table from an
 * unreviewed leak: the isolation canary (tests/Feature/Tenancy) treats an
 * unmarked, unprotected tenant table as an offender.
 */
trait CentralModel
{
}
