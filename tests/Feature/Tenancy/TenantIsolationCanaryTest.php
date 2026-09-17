<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenantContext;
use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\Exceptions\TenantResolutionConflictException;
use App\Support\Tenancy\TeamContext;
use App\Support\Tenancy\TenancyEnforcement;
use App\Support\Tenancy\TenancyEnforcementMode;
use App\Support\Tenancy\TeamScope;
use App\Support\Tenancy\TenantModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| T2.3 — Tenancy canary + conflict e2e (ADR-001 Fase 4 / §5)
|--------------------------------------------------------------------------
*/

/**
 * The frozen list of tenant-owned models that are, today, neither scoped nor
 * explicitly declared. Kept out of the failure set so existing debt does not
 * block every deploy — while any NEW offender still breaks the build.
 *
 * @return list<class-string>
 */
function tenancyUnprotectedBaseline(): array
{
    $path = config('tenancy.unprotected_baseline');

    if (! is_string($path) || ! is_file($path)) {
        return [];
    }

    /** @var list<class-string> $baseline */
    $baseline = require $path;

    return array_values(array_unique($baseline));
}

/** Tenant-owned models that are neither scoped nor declared central/shared. */
function tenancyUnprotectedOffenders(): array
{
    $offenders = [];

    foreach ((new TenantModelRegistry(base_path()))->tenantOwned() as $class => $info) {
        $declared = $info['category'];
        $protected = $info['protected'] || in_array($declared, ['central', 'shared_or_tenant'], true);

        if (! $protected) {
            $offenders[$class] = $info['table'];
        }
    }

    ksort($offenders);

    return $offenders;
}

it('does not let a NEW tenant-owned model ship unprotected', function () {
    $offenders = tenancyUnprotectedOffenders();
    $baseline = tenancyUnprotectedBaseline();

    $new = array_values(array_diff(array_keys($offenders), $baseline));
    sort($new);

    expect($new)->toBe([], sprintf(
        "New tenant-owned models are neither scoped (BelongsToCurrentTeam) nor declared central/shared:\n - %s\n\n"
        .'Protect the model (use BelongsToCurrentTeam) or declare it (CentralModel / SharedOrTenantModel). '
        .'If the gap is intentional for now, add it to %s.',
        implode("\n - ", $new),
        (string) config('tenancy.unprotected_baseline'),
    ));
});

it('keeps the frozen baseline an exact mirror of the current offenders (only shrinks)', function () {
    $offenders = array_keys(tenancyUnprotectedOffenders());
    $baseline = tenancyUnprotectedBaseline();

    $stale = array_values(array_diff($baseline, $offenders));
    sort($stale);

    expect($stale)->toBe([], sprintf(
        "These models are in the unprotected baseline but are no longer offenders — remove them from %s:\n - %s",
        (string) config('tenancy.unprotected_baseline'),
        implode("\n - ", $stale),
    ));
});

it('treats the legacy fail-closed behaviour as the default (no silent regression)', function () {
    expect(config('tenancy.enforcement'))->toBe('off')
        ->and(TenancyEnforcementMode::fromConfig('off'))->toBe(TenancyEnforcementMode::LEGACY)
        ->and(app(TenancyEnforcement::class)->mode())->toBe(TenancyEnforcementMode::LEGACY);
});

it('fails LOUD on tenant-owned access without context under ENFORCE', function () {
    app()->instance(TenancyEnforcement::class, new TenancyEnforcement(TenancyEnforcementMode::ENFORCE));

    $team = new class extends Illuminate\Database\Eloquent\Model
    {
        protected $table = 'invoices';

        protected $guarded = [];
    };

    expect(fn () => (new TeamScope())->apply($team->newQuery(), $team))
        ->toThrow(MissingTenantContextException::class);
});

it('refuses a request whose route tenant disagrees with the session (403, never TENANT(route))', function () {
    $reached = false;

    Route::middleware('web')->get('/t/{tenant}/secret', function () use (&$reached): string {
        $reached = true;

        return 'secret';
    })->name('tenant.secret');

    $user = User::factory()->create();
    $user->forceFill(['current_team_id' => 10])->save();

    $this->actingAs($user->fresh())
        ->get('/t/20/secret')
        ->assertForbidden()
        ->assertDontSee('secret');

    // The route handler must never have run, and the context must not have
    // silently adopted the route's tenant.
    expect($reached)->toBeFalse()
        ->and(app(TeamContext::class)->tenantId())->not->toBe(20);
});

it('resolves the session tenant for a non-conflicting request', function () {
    $seen = null;

    Route::middleware('web')->get('/t/secret', function () use (&$seen): string {
        $seen = app(TeamContext::class)->tenantId();

        return 'ok';
    })->name('tenant.secret.same');

    $user = User::factory()->create();
    $user->forceFill(['current_team_id' => 10])->save();

    $this->actingAs($user->fresh())->get('/t/secret')->assertOk();

    expect($seen)->toBe(10);
});
