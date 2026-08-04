<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;

/*
|--------------------------------------------------------------------------
| Catalog — authorization (§9, ADR-013/030/040)
|--------------------------------------------------------------------------
|
| Two audiences with genuinely different rules, and one wall between sellers.
| The tests that matter most are the negative ones: a seller must not reach
| another seller's proposal, and must not reach the moderation verdicts at all.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who owns an organization — the shape the tenancy wall resolves
 * against (ADR-030).
 *
 * @return array{seller: Seller, organization: Organization}
 */
function sellerWithOrganization(): array
{
    // ->owner() attaches the `seller` role, which is what carries the
    // catalog permissions. A bare Seller::factory() has no role at all, so a
    // helper without it would test an actor no real signup produces.
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()
        ->for($organization)
        ->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return ['seller' => $seller, 'organization' => $organization];
}

function productProposedBy(Organization $organization, ProductStatus $status = ProductStatus::Draft): Product
{
    $root = Category::factory()->create();
    $leaf = Category::factory()->childOf($root)->create();

    return Product::factory()
        ->for($leaf, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->create(['status' => $status]);
}

it('lets a category manager manage the taxonomy', function (): void {
    // ADR-013 reserved this role for exactly this module; it finally does the
    // job it was created for.
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);

    $category = Category::factory()->create();

    expect($admin->can('create', Category::class))->toBeTrue()
        ->and($admin->can('update', $category))->toBeTrue()
        ->and($admin->can('archive', $category))->toBeTrue();
});

it('lets a category manager moderate products', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);

    ['organization' => $organization] = sellerWithOrganization();
    $product = productProposedBy($organization, ProductStatus::PendingReview);

    expect($admin->can('publish', $product))->toBeTrue()
        ->and($admin->can('reject', $product))->toBeTrue()
        ->and($admin->can('requestRevision', $product))->toBeTrue();
});

it('denies taxonomy writes to an admin role that does not hold the permission', function (): void {
    // Support is a helpdesk role: it reads accounts, it does not edit the
    // catalog. Policies check permissions, never roles — this proves the
    // permission is what gates it.
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.support')]);

    $category = Category::factory()->create();

    expect($admin->can('update', $category))->toBeFalse()
        ->and($admin->can('create', Category::class))->toBeFalse();
});

it('lets a seller author and edit their own proposal', function (): void {
    ['seller' => $seller, 'organization' => $organization] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $product = productProposedBy($organization);

    expect($seller->can('create', Product::class))->toBeTrue()
        ->and($seller->can('view', $product))->toBeTrue()
        ->and($seller->can('update', $product))->toBeTrue();
});

it('walls one seller off from another seller\'s proposal', function (): void {
    // ADR-030/040 — the single most important negative in this module.
    ['organization' => $theirOrganization] = sellerWithOrganization();
    ['seller' => $intruder] = sellerWithOrganization();

    $this->actingAsSeller($intruder);

    $theirProduct = productProposedBy($theirOrganization);

    expect($intruder->can('view', $theirProduct))->toBeFalse()
        ->and($intruder->can('update', $theirProduct))->toBeFalse();
});

it('stops a seller editing their own product once it is under review', function (): void {
    // A product must not change underneath the moderator reviewing it (§3.1).
    ['seller' => $seller, 'organization' => $organization] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $product = productProposedBy($organization, ProductStatus::PendingReview);

    expect($seller->can('update', $product))->toBeFalse();
});

it('stops a seller editing their own product once it is published', function (): void {
    // ADR-037 — a published product belongs to the shared catalog, not to
    // whoever proposed it.
    ['seller' => $seller, 'organization' => $organization] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $product = productProposedBy($organization, ProductStatus::Published);

    expect($seller->can('update', $product))->toBeFalse();
});

it('lets a seller edit again after a revision request', function (): void {
    // The humane middle path has to actually give the product back.
    ['seller' => $seller, 'organization' => $organization] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $product = productProposedBy($organization, ProductStatus::NeedsRevision);

    expect($seller->can('update', $product))->toBeTrue();
});

it('never lets a seller moderate anything, including their own product', function (): void {
    // Moderation is absent from the seller guard entirely, not merely
    // unassigned — so this cannot be fixed by giving a seller a role.
    ['seller' => $seller, 'organization' => $organization] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $product = productProposedBy($organization, ProductStatus::PendingReview);

    expect($seller->can('publish', $product))->toBeFalse()
        ->and($seller->can('reject', $product))->toBeFalse()
        ->and($seller->can('requestRevision', $product))->toBeFalse()
        ->and($seller->can('archive', $product))->toBeFalse();
});

it('never lets a seller write the taxonomy', function (): void {
    // The ADR-038 bargain: sellers get consistent categories, and pay for them
    // by not being able to invent any.
    ['seller' => $seller] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $category = Category::factory()->create();

    expect($seller->can('create', Category::class))->toBeFalse()
        ->and($seller->can('update', $category))->toBeFalse();
});

it('lets a seller read the taxonomy they must file against', function (): void {
    ['seller' => $seller] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $category = Category::factory()->create();

    expect($seller->can('view', $category))->toBeTrue()
        ->and($seller->can('viewAny', Category::class))->toBeTrue();
});

it('treats a staff-authored product as nobody\'s to edit from the seller panel', function (): void {
    ['seller' => $seller] = sellerWithOrganization();
    $this->actingAsSeller($seller);

    $root = Category::factory()->create();
    $leaf = Category::factory()->childOf($root)->create();
    $product = Product::factory()->for($leaf, 'category')->create();

    expect($seller->can('update', $product))->toBeFalse()
        ->and($seller->can('view', $product))->toBeFalse();
});

it('gives super admin everything through the base policy bypass', function (): void {
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.super_admin')]);

    ['organization' => $organization] = sellerWithOrganization();
    $product = productProposedBy($organization, ProductStatus::PendingReview);
    $category = Category::factory()->create();

    expect($admin->can('publish', $product))->toBeTrue()
        ->and($admin->can('update', $category))->toBeTrue();
});

it('lets a seller employee author products for their organization', function (): void {
    // Opening a product is merchandising, not a financial or legal act —
    // withholding it would leave the owner personally filing every product.
    ['organization' => $organization] = sellerWithOrganization();

    /** @var Seller $employee */
    $employee = Seller::factory()->employee()->create();

    OrganizationMember::factory()
        ->for($organization)
        ->role(OrganizationRole::Editor)
        ->create(['user_id' => $employee->getKey()]);

    $this->actingAsSeller($employee);

    $product = productProposedBy($organization);

    expect($employee->can('create', Product::class))->toBeTrue()
        ->and($employee->can('update', $product))->toBeTrue();
});
