<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team-scoped isolation audit (Fase 0.5 / T0.5.4).
 *
 * Liberu's tenancy is team-scoped on a SINGLE database: rows carry a team_id and
 * isolation is applied per query. There is NO DB-per-tenant and — crucially — no
 * automatic global scope: the IsTenantModel trait only adds a `team()` relation.
 * These tests pin that contract down so a future refactor cannot silently assume
 * isolation is automatic.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $name): Team
    {
        return Team::forceCreate([
            'user_id' => User::factory()->create()->id,
            'name' => $name,
            'personal_team' => true,
        ]);
    }

    public function test_provisioning_scopes_accounts_to_their_own_team(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');

        $service = app(TenantProvisioningService::class);
        $service->provisionChartOfAccounts($a);
        $service->provisionChartOfAccounts($b);

        $this->assertSame(18, Account::where('team_id', $a->getKey())->count());
        $this->assertSame(18, Account::where('team_id', $b->getKey())->count());
        $this->assertSame(36, Account::query()->count());
    }

    public function test_scoped_query_never_returns_another_teams_rows(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');

        $service = app(TenantProvisioningService::class);
        $service->provisionChartOfAccounts($a);
        $service->provisionChartOfAccounts($b);

        // The team-scoped read used by every module Query class must not leak.
        $teamIdsVisibleToA = Account::query()
            ->where('team_id', $a->getKey())
            ->pluck('team_id')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([$a->getKey()], $teamIdsVisibleToA);
    }

    public function test_isolation_is_opt_in_because_there_is_no_global_scope(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');

        $service = app(TenantProvisioningService::class);
        $service->provisionChartOfAccounts($a);
        $service->provisionChartOfAccounts($b);

        // SECURITY CONTRACT: with no global scope, an UNSCOPED read sees every
        // team. This is why each Query/Resource/Policy MUST filter by team_id —
        // forgetting to is a cross-tenant data leak, not a caught error.
        $this->assertSame(36, Account::query()->count());
        $this->assertSame(2, Account::query()->distinct()->count('team_id'));

        // The tenant trait exposes only the relation; it registers no scope.
        $this->assertTrue(method_exists(Account::class, 'team'));
        $this->assertFalse(method_exists(Account::class, 'bootIsTenantModel'));
    }
}