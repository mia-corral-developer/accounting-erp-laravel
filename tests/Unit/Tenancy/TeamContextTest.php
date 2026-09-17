<?php

declare(strict_types=1);

use App\Support\Tenancy\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\TeamContext;
use App\Support\Tenancy\TenancyState;

it('starts UNRESOLVED with no tenant', function () {
    $ctx = new TeamContext();

    expect($ctx->state())->toBe(TenancyState::UNRESOLVED)
        ->and($ctx->isResolved())->toBeFalse()
        ->and($ctx->isTenant())->toBeFalse()
        ->and($ctx->isCentral())->toBeFalse()
        ->and($ctx->tenantId())->toBeNull();
});

it('activates a tenant explicitly', function () {
    $ctx = new TeamContext();
    $ctx->activateTenant(42);

    expect($ctx->state())->toBe(TenancyState::TENANT)
        ->and($ctx->isTenant())->toBeTrue()
        ->and($ctx->isResolved())->toBeTrue()
        ->and($ctx->tenantId())->toBe(42)
        ->and($ctx->requireTenant())->toBe(42);
});

it('throws LOUD when a tenant is required while UNRESOLVED', function () {
    (new TeamContext())->requireTenant('invoices');
})->throws(MissingTenantContextException::class);

it('runTenant scopes the callback and restores the previous state', function () {
    $ctx = new TeamContext();
    $observed = null;

    $result = $ctx->runTenant(7, function () use ($ctx, &$observed) {
        $observed = $ctx->tenantId();

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($observed)->toBe(7)
        ->and($ctx->state())->toBe(TenancyState::UNRESOLVED)
        ->and($ctx->tenantId())->toBeNull();
});

it('runCentral elevates to CENTRAL and restores the tenant afterwards', function () {
    $ctx = new TeamContext();
    $ctx->activateTenant(3);
    $inside = null;

    $ctx->runCentral('seed platform defaults', function () use ($ctx, &$inside) {
        $inside = $ctx->state();
    });

    expect($inside)->toBe(TenancyState::CENTRAL)
        ->and($ctx->state())->toBe(TenancyState::TENANT)
        ->and($ctx->tenantId())->toBe(3);
});

it('forget returns to UNRESOLVED', function () {
    $ctx = new TeamContext();
    $ctx->activateTenant(9);
    $ctx->forget();

    expect($ctx->state())->toBe(TenancyState::UNRESOLVED)
        ->and($ctx->isResolved())->toBeFalse()
        ->and($ctx->tenantId())->toBeNull();
});

it('is resolved by the container as a scoped instance', function () {
    expect(app(TeamContext::class))->toBe(app(TeamContext::class));
});
