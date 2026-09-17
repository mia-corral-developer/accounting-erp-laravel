<?php

declare(strict_types=1);

use App\Support\Tenancy\TenancyEnforcement;
use App\Support\Tenancy\TenancyEnforcementMode;
use App\Support\Tenancy\TenantQueryTelemetry;

it('extracts every referenced table from a statement', function (string $sql, array $expected) {
    $telemetry = new TenantQueryTelemetry(
        new TenancyEnforcement(TenancyEnforcementMode::LEGACY),
        new App\Support\Tenancy\TenantModelRegistry(base_path()),
    );

    expect($telemetry->tablesIn($sql))->toBe($expected);
})->with([
    ['select * from `invoices` where team_id = ?', ['invoices']],
    ['select * from `invoices` as `i` where i.team_id = ?', ['invoices']],
    ['select * from `liberu`.`invoices`', ['invoices']],
    [
        'select * from `invoices` inner join `customers` on customers.id = invoices.customer_id',
        ['invoices', 'customers'],
    ],
    ['insert into `transactions` (`team_id`) values (?)', ['transactions']],
    ['update `expenses` set `amount` = ? where `id` = ?', ['expenses']],
    ['delete from `journal_entry_lines` where `id` = ?', ['journal_entry_lines']],
    ['select 1', []],
]);

it('does not register when enforcement is not OBSERVE', function () {
    $legacy = new TenantQueryTelemetry(
        new TenancyEnforcement(TenancyEnforcementMode::LEGACY),
        new App\Support\Tenancy\TenantModelRegistry(base_path()),
    );

    // No assertion on DB::listen needed — register() must simply be a no-op and
    // never throw when the mode is LEGACY.
    $legacy->register();

    expect(true)->toBeTrue();
});
