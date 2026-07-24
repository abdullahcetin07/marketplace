<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrganizationAuthorizationContract;
use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
|--------------------------------------------------------------------------
| Store — cross-context authorization & isolation (ADR-030, ADR-033, §9)
|--------------------------------------------------------------------------
|
| Store authorises seller actions through the Core OrganizationAuthorizationContract
| — never importing Organization's membership model. A member of one org can do
| nothing to another org's store; domains are Owner-only.
*/

beforeEach(function (): void {
    $this->seedAll();
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * Attach a user to an org with a role, returning the user.
 */
function memberOf(Organization $org, OrganizationRole $role): Seller
{
    $user = Seller::factory()->create();
    OrganizationMember::factory()->for($org)->role($role)->create(['user_id' => $user->getKey()]);

    return $user;
}

it('answers the authorization contract by role (StoreManage)', function (): void {
    $org = Organization::factory()->create();
    $owner = memberOf($org, OrganizationRole::Owner);
    $manager = memberOf($org, OrganizationRole::Manager);
    $finance = memberOf($org, OrganizationRole::Finance);

    /** @var OrganizationAuthorizationContract $authz */
    $authz = app(OrganizationAuthorizationContract::class);

    // Owner and Manager may run the storefront.
    expect($authz->canManageOrganization($owner->getKey(), $org->getKey()))->toBeTrue()
        ->and($authz->canManageOrganization($manager->getKey(), $org->getKey()))->toBeTrue();

    // Finance: an active member, but no store management.
    expect($authz->isActiveMember($finance->getKey(), $org->getKey()))->toBeTrue()
        ->and($authz->canManageOrganization($finance->getKey(), $org->getKey()))->toBeFalse();
});

it('denies a non-member everything', function (): void {
    $org = Organization::factory()->create();
    $stranger = Seller::factory()->create();

    /** @var OrganizationAuthorizationContract $authz */
    $authz = app(OrganizationAuthorizationContract::class);

    expect($authz->isActiveMember($stranger->getKey(), $org->getKey()))->toBeFalse()
        ->and($authz->canManageOrganization($stranger->getKey(), $org->getKey()))->toBeFalse();
});

it('lets a manager of the owning org manage the store', function (): void {
    $org = Organization::factory()->create();
    $manager = memberOf($org, OrganizationRole::Manager);
    $store = Store::factory()->create(['organization_id' => $org->getKey()]);

    expect($manager->can('update', $store))->toBeTrue()
        ->and($manager->can('activate', $store))->toBeTrue();
});

it('isolates a store from a member of a different organization (ADR-030)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $managerB = memberOf($orgB, OrganizationRole::Manager);
    $storeA = Store::factory()->create(['organization_id' => $orgA->getKey()]);

    expect($managerB->can('view', $storeA))->toBeFalse()
        ->and($managerB->can('update', $storeA))->toBeFalse()
        ->and($managerB->can('activate', $storeA))->toBeFalse();
});

it('gates admin suspension on the store.suspend permission, not membership', function (): void {
    $org = Organization::factory()->create();
    $store = Store::factory()->active()->create(['organization_id' => $org->getKey()]);

    $withPermission = $this->grant(Admin::factory()->create(), 'store.suspend');
    $withoutPermission = Admin::factory()->create();

    expect($withPermission->can('suspend', $store))->toBeTrue()
        ->and($withoutPermission->can('suspend', $store))->toBeFalse();
});
