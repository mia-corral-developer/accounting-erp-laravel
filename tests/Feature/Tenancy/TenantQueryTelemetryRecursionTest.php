<?php

declare(strict_types=1);

use App\Support\Tenancy\TenancyEnforcement;
use App\Support\Tenancy\TenancyEnforcementMode;
use App\Support\Tenancy\TenantModelRegistry;
use App\Support\Tenancy\TenantQueryTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| TenantQueryTelemetry — recursion regression (ADR-001 T2.4)
|--------------------------------------------------------------------------
|
| The listener reads the schema to know which tables carry team_id. That read
| is itself a query — so if the re-entrancy guard is set *after* building the
| table set, those queries re-enter the listener and recurse without bound
| (this crashed the runtime container with a ~12k-frame stack).
|
| This test attaches the listener for real (force) and runs a tenant-owned
| query with no context. Without the guard-first fix it recurses until the
| process dies; with the fix it logs once and returns.
|
*/

it('observes a tenant-owned query without context and does not recurse', function () {
    Log::spy();

    $telemetry = new TenantQueryTelemetry(
        new TenancyEnforcement(TenancyEnforcementMode::OBSERVE),
        new TenantModelRegistry(base_path()),
    );

    $telemetry->register(force: true);

    DB::table('invoices')->limit(1)->get();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'Tenant-owned query executed without resolved context'))
        ->once();
});
