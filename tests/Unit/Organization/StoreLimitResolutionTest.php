<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationPlan;

/*
|--------------------------------------------------------------------------
| Store-limit resolution (ADR-028)
|--------------------------------------------------------------------------
|
| A UNIT test — no database. Resolution is by SOURCE precedence (override →
| plan → system default), and a plan's null limit is a real answer (unlimited),
| not an absence. Models are built in memory; the plan relation is set
| explicitly so nothing lazy-loads.
|
| @see App\Modules\Organization\Domain\Models\Organization::effectiveStoreLimit()
*/

it('prefers the per-organization override above everything', function (): void {
    config()->set('marketplace.organization.default_store_limit', 1);

    $org = new Organization(['store_limit_override' => 9, 'plan_id' => 5]);
    $org->setRelation('plan', new OrganizationPlan(['store_limit' => 3]));

    expect($org->effectiveStoreLimit())->toBe(9);
});

it('falls back to the plan limit when there is no override', function (): void {
    $org = new Organization(['plan_id' => 5]);
    $org->setRelation('plan', new OrganizationPlan(['store_limit' => 3]));

    expect($org->effectiveStoreLimit())->toBe(3);
});

it('treats a null plan limit as unlimited, not as absent', function (): void {
    $org = new Organization(['plan_id' => 5]);
    $org->setRelation('plan', new OrganizationPlan(['store_limit' => null]));

    // The plan is the SOURCE; its null limit is the answer — resolution does not
    // skip to the config default.
    expect($org->effectiveStoreLimit())->toBeNull();
});

it('falls back to the system default with no override or plan', function (): void {
    config()->set('marketplace.organization.default_store_limit', 2);

    expect((new Organization([]))->effectiveStoreLimit())->toBe(2);
});

it('supports an unlimited system default', function (): void {
    config()->set('marketplace.organization.default_store_limit', null);

    expect((new Organization([]))->effectiveStoreLimit())->toBeNull();
});

it('reports remaining slots, and null when unlimited', function (): void {
    config()->set('marketplace.organization.default_store_limit', 3);
    // Phase 1 has no stores, so currentStoreCount() is 0.
    expect((new Organization([]))->remainingStoreSlots())->toBe(3);

    config()->set('marketplace.organization.default_store_limit', null);
    expect((new Organization([]))->remainingStoreSlots())->toBeNull();
});
