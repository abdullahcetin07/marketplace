<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Models\User;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/*
|--------------------------------------------------------------------------
| OfferPolicy — two audiences, two mechanisms (§9)
|--------------------------------------------------------------------------
|
| Seller authority is an ORGANIZATION CAPABILITY resolved through the Core
| contract: there is no seller-guard `offer.update` to hold, because Spatie
| `teams` is false and such a permission could not be scoped to one company —
| it would grant power over everyone's offers. Admin authority IS a Spatie
| permission, because it is a cross-org platform power.
|
| The assertion that matters most is the tenancy one: a seller from another
| company is denied by construction (ADR-030). Everything else here is a
| corollary of that or of the admin/seller split.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller seated in a company with the given org role, plus an offer that
 * company sells.
 *
 * The factory is declared on the base User, so the shape says User; the
 * `seller` guard is what makes it a Seller in every assertion below.
 *
 * @return array{seller: User, org: Organization, offer: Offer}
 */
function sellerWithOffer(OrganizationRole $role = OrganizationRole::Owner): array
{
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role($role)
        ->create(['user_id' => $seller->getKey()]);

    return [
        'seller' => $seller,
        'org' => $organization,
        'offer' => Offer::factory()
            ->forOrganization($organization->getKey(), $organization->uuid)
            ->create(),
    ];
}

it('lets an Owner manage their own company’s offer', function (): void {
    $fixture = sellerWithOffer();
    $seller = $this->actingAsSeller($fixture['seller']);

    expect($seller->can('view', $fixture['offer']))->toBeTrue()
        ->and($seller->can('update', $fixture['offer']))->toBeTrue()
        ->and($seller->can('pause', $fixture['offer']))->toBeTrue()
        ->and($seller->can('resume', $fixture['offer']))->toBeTrue()
        ->and($seller->can('withdraw', $fixture['offer']))->toBeTrue();
});

it('lets a Manager manage them too — the capability, not the role name', function (): void {
    $fixture = sellerWithOffer(OrganizationRole::Manager);
    $seller = $this->actingAsSeller($fixture['seller']);

    expect($seller->can('update', $fixture['offer']))->toBeTrue();
});

it('denies a member whose role does not grant management', function (): void {
    $fixture = sellerWithOffer(OrganizationRole::Viewer);
    $seller = $this->actingAsSeller($fixture['seller']);

    // A Viewer belongs to the company, so they may read what it sells …
    expect($seller->can('view', $fixture['offer']))->toBeTrue()
        // … and may not re-price it.
        ->and($seller->can('update', $fixture['offer']))->toBeFalse()
        ->and($seller->can('withdraw', $fixture['offer']))->toBeFalse();
});

it('denies a seller from another company everything — the tenancy wall', function (): void {
    $theirs = sellerWithOffer();
    $mine = sellerWithOffer();

    $outsider = $this->actingAsSeller($mine['seller']);

    // ADR-030. Not "hidden in the UI" — denied by the policy, whatever surface
    // the request arrives through.
    expect($outsider->can('view', $theirs['offer']))->toBeFalse()
        ->and($outsider->can('update', $theirs['offer']))->toBeFalse()
        ->and($outsider->can('pause', $theirs['offer']))->toBeFalse()
        ->and($outsider->can('withdraw', $theirs['offer']))->toBeFalse();
});

it('never lets a seller suspend anything, including their own offer', function (): void {
    $fixture = sellerWithOffer();
    $seller = $this->actingAsSeller($fixture['seller']);

    // Suspension is oversight. A seller suspending a competitor's listing is
    // the attack this separation exists to prevent, and there is no capability
    // that could grant it.
    expect($seller->can('suspend', $fixture['offer']))->toBeFalse()
        ->and($seller->can('reinstate', $fixture['offer']))->toBeFalse();
});

it('lets an admin suspend and reinstate but never re-price', function (): void {
    $fixture = sellerWithOffer();

    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.admin')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    expect($admin->can('view', $fixture['offer']))->toBeTrue()
        ->and($admin->can('suspend', $fixture['offer']))->toBeTrue()
        ->and($admin->can('reinstate', $fixture['offer']))->toBeTrue()
        // Editing a merchant's price is not oversight — it is trading on their
        // behalf, and the audit trail would name the wrong party.
        ->and($admin->can('update', $fixture['offer']))->toBeFalse()
        ->and($admin->can('create', Offer::class))->toBeFalse();
});

it('gives Support read access but not the power to pull a listing', function (): void {
    $fixture = sellerWithOffer();

    $support = $this->actingAsAdmin();
    $support->syncRoles([config('marketplace.roles.support')]);
    $support->refresh()->loadMissing('roles.permissions', 'permissions');

    // A helpdesk answers "why is my listing not showing?"; pulling it is a
    // different power (ADR-044).
    expect($support->can('viewAny', Offer::class))->toBeTrue()
        ->and($support->can('view', $fixture['offer']))->toBeTrue()
        ->and($support->can('suspend', $fixture['offer']))->toBeFalse()
        ->and($support->can('reinstate', $fixture['offer']))->toBeFalse();
});

it('lets a super admin do anything', function (): void {
    $fixture = sellerWithOffer();

    $superAdmin = $this->actingAsAdmin();
    $superAdmin->syncRoles([config('marketplace.roles.super_admin')]);
    $superAdmin->refresh()->loadMissing('roles.permissions', 'permissions');

    expect($superAdmin->can('suspend', $fixture['offer']))->toBeTrue()
        ->and($superAdmin->can('update', $fixture['offer']))->toBeTrue();
});

it('lets a seller who manages a company start listing, and one who manages none not', function (): void {
    $manager = sellerWithOffer(OrganizationRole::Manager);
    expect($this->actingAsSeller($manager['seller'])->can('create', Offer::class))->toBeTrue();

    // A seller with no membership at all has no company to sell on behalf of.
    expect($this->actingAsSeller(Seller::factory()->create())->can('create', Offer::class))->toBeFalse();

    // And a member without the capability cannot start one either.
    $viewer = sellerWithOffer(OrganizationRole::Viewer);
    expect($this->actingAsSeller($viewer['seller'])->can('create', Offer::class))->toBeFalse();
});

it('answers the per-company create question a form actually asks', function (): void {
    $mine = sellerWithOffer();
    $theirs = sellerWithOffer();

    $seller = $this->actingAsSeller($mine['seller']);

    // `create` only says "you manage something"; this is the check that stops a
    // forged organization id in a create payload.
    expect($seller->can('createFor', [Offer::class, $mine['org']->getKey()]))->toBeTrue()
        ->and($seller->can('createFor', [Offer::class, $theirs['org']->getKey()]))->toBeFalse();
});

it('denies a customer outright', function (): void {
    $fixture = sellerWithOffer();
    $customer = $this->actingAsCustomer();

    expect($customer->can('viewAny', Offer::class))->toBeFalse()
        ->and($customer->can('view', $fixture['offer']))->toBeFalse()
        ->and($customer->can('create', Offer::class))->toBeFalse();
});
