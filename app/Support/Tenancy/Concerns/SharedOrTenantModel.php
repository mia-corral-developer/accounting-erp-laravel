<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Concerns;

/**
 * Declarative marker: this model has a `team_id` column that is meaningful for
 * some rows but the table is also read centrally (e.g. templates a team may
 * own or clone from a shared set). It is not auto-scoped; callers choose the
 * scope explicitly. Marking it acknowledges the dual nature instead of leaving
 * the table silently unscoped.
 */
trait SharedOrTenantModel
{
}
