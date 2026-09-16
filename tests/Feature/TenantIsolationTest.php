<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\Tenancy\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team-scoped isolation audit (Fase 0.5 / T0.5.4) + guardrail (T0.6).
 *
 * Liberu's tenancy is team-scoped on a SINGLE database and isolation is applied
 * per query. The legacy IsTenantModel trait only adds a `team()` relation (no
 * global scope). The new App\Models\Concerns\BelongsToCurrentTeam trait adds a
 * global TeamScope so reads can't accidentally cross teams.
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

    private function provision(Team $a, Team $b): void
    {
        $service = app(TenantProvisioningService::class);
        $service->provisionChartOfAccounts($a);
        $service->provisionChartOfAccounts($b);
    }

    /** Without an explicit team context there is no global scope -> wide read. */
    public function test_isolation_is_opt_in_without_team_context(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $this->provision($a, $b);

        // No auth, no TeamContext: the guardrail leaves queries unscoped.
        $this->assertSame(36, Account::query()->count());
        $this->assertSame(2, Account::query()->distinct()->count('team_id'));
    }

    public function test_provisioning_scopes_accounts_to_their_own_team(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $this->provision($a, $b);

        $this->assertSame(18, Account::withoutTeamScope()->where('team_id', $a->getKey())->count());
        $this->assertSame(18, Account::withoutTeamScope()->where('team_id', $b->getKey())->count());
    }

    /** The guardrail: with a current team bound, reads are auto-restricted. */
    public function test_guardrail_auto_scopes_reads_to_current_team(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $this->provision($a, $b);

        $context = app(TeamContext::class);

        $context->set($a->getKey());
        $this->assertSame(18, Account::query()->count());
        $this->assertSame([$a->getKey()], Account::query()->pluck('team_id')->unique()->values()->all());

        $context->set($b->getKey());
        $this->assertSame(18, Account::query()->count());
        $this->assertSame([$b->getKey()], Account::query()->pluck('team_id')->unique()->values()->all());

        // Escape hatch returns to an unscoped read.
        $this->assertSame(36, Account::withoutTeamScope()->count());

        $context->forget();
    }

    /** Fail closed: a signed-in user without a team must never read other teams. */
    public function test_guardrail_fails_closed_for_authenticated_user_without_team(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $this->provision($a, $b);

        $this->actingAs(User::factory()->create(['current_team_id' => null]));

        $this->assertSame(0, Account::query()->count());
    }

    /** Run() scopes and restores the previous context. */
    public function test_team_context_run_restores_previous_context(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $this->provision($a, $b);

        $context = app(TeamContext::class);
        $context->set($a->getKey());

        $insideB = $context->run($b->getKey(), fn (): int => Account::query()->count());

        $this->assertSame(18, $insideB);
        $this->assertSame($a->getKey(), $context->id());
        $this->assertSame(18, Account::query()->count());

        $context->forget();
    }

    /** The tenant trait exposes only the relation; it registers no scope itself. */
    public function test_tenant_model_has_no_standalone_global_scope(): void
    {
        $this->assertTrue(method_exists(Account::class, 'team'));
        $this->assertTrue(method_exists(Account::class, 'scopeWithoutTeamScope'));
    }
}