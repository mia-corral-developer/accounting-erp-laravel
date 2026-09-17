<?php

declare(strict_types=1);

use App\Support\Tenancy\TenancyEnforcementMode;

it('maps the kill-switch values to modes', function (string $value, TenancyEnforcementMode $expected) {
    expect(TenancyEnforcementMode::fromConfig($value))->toBe($expected);
})->with([
    ['off', TenancyEnforcementMode::LEGACY],
    ['legacy', TenancyEnforcementMode::LEGACY],
    ['per_model', TenancyEnforcementMode::OBSERVE],
    ['observe', TenancyEnforcementMode::OBSERVE],
    ['strict', TenancyEnforcementMode::ENFORCE],
    ['enforce', TenancyEnforcementMode::ENFORCE],
    ['  STRICT  ', TenancyEnforcementMode::ENFORCE],
    ['nonsense', TenancyEnforcementMode::LEGACY],
]);

it('degrades to the safe default when the value is unknown', function () {
    expect(TenancyEnforcementMode::fromConfig(''))->toBe(TenancyEnforcementMode::LEGACY);
});

it('reports its posture', function () {
    expect(TenancyEnforcementMode::ENFORCE->isEnforcing())->toBeTrue()
        ->and(TenancyEnforcementMode::ENFORCE->isObserving())->toBeFalse()
        ->and(TenancyEnforcementMode::OBSERVE->isObserving())->toBeTrue()
        ->and(TenancyEnforcementMode::LEGACY->isEnforcing())->toBeFalse();
});
