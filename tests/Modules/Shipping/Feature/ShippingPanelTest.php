<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource\Pages\ListCargoCompanies;
use App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource\Pages\ListShipments;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The two shipping screens, actually rendered
|--------------------------------------------------------------------------
|
| RENDERED, NOT SMOKE-TESTED. A page whose chrome renders while every row throws
| still passes an `assertOk()`, so these assert COLUMN STATE and table actions.
|
| **AND EVERY TABLE FIXTURE SEEDS TWO ROWS**, because Laravel only arms the
| lazy-loading guard when a query hydrates more than one model
| (`Builder::hydrate()`, `count($items) > 1`) — a one-record fixture renders a
| missing eager load happily. That trap cost two screens on this platform already.
|
| WHAT THESE TESTS DO *NOT* PROVE, stated so nobody trusts them for it: they do
| not catch a missing `with('cargoCompany')`. Filament eager-loads a DOTTED
| column's relationship by itself (`InteractsWithTableQuery::applyEagerLoading()`),
| so the carrier column arranges its own safety and the resource's explicit eager
| load is defence for the tracking-URL CLOSURE, which the framework cannot see.
| Verified by removing the eager load and watching these still pass — a regression
| test that has never been seen to fail is a claim, not evidence.
|
| The tenancy wall is the other thing worth pinning: a seller sees their own
| company's parcels and nobody else's. A failure there is a cross-tenant leak, not
| a UI bug.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who belongs to a company, plus a parcel of theirs. Named for this file
 * because Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, shipment: Shipment}
 */
function sellerWithShipment(OrganizationRole $role = OrganizationRole::Owner): array
{
    $organization = Organization::factory()->create();

    /** @var Seller $seller */
    $seller = Seller::factory()->create();

    OrganizationMember::factory()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $seller->getKey(),
        'role' => $role,
    ]);

    return [
        'seller' => $seller,
        'org' => $organization,
        'shipment' => Shipment::factory()->forSeller($organization->uuid)->create(),
    ];
}

/*
|--------------------------------------------------------------------------
| "Kargolarım"
|--------------------------------------------------------------------------
*/

it('shows a seller their own parcels and nobody else’s', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $mine = sellerWithShipment();
    $theirs = sellerWithShipment();

    $this->actingAs($mine['seller'], 'seller');

    /*
     * THE WALL GOES THROUGH THE ORGANIZATION UUID, because a shipment carries the
     * seller as a uuid while the Core authorization contract answers in internal
     * ids. That indirection is the price of Shipping importing no module.
     */
    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$mine['shipment']])
        ->assertCanNotSeeTableRecords([$theirs['shipment']]);
});

it('renders the carrier and the tracking link on a shipped parcel', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithShipment();
    $carrier = CargoCompany::query()->where('code', 'yurtici')->firstOrFail();

    $shipped = Shipment::factory()
        ->forSeller($fixture['org']->uuid)
        ->shipped()
        ->create(['cargo_company_id' => $carrier->getKey(), 'tracking_number' => '1234567890']);

    $this->actingAs($fixture['seller'], 'seller');

    /*
     * TWO ROWS, so the strict-mode guard is armed at all — and the carrier name
     * comes off the relation, which is what a render has to touch. @see the note
     * at the top for what this does and does not prove.
     */
    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$shipped, $fixture['shipment']])
        ->assertTableColumnStateSet('cargoCompany.name', $carrier->name, $shipped);

    // And the link the buyer follows, built from the carrier's own template.
    expect($shipped->fresh()->trackingUrl())->toContain('1234567890');
});

it('offers "kargoya ver" only while the parcel is still the seller’s to hand over', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithShipment();
    $shipped = Shipment::factory()->forSeller($fixture['org']->uuid)->shipped()->create();

    $this->actingAs($fixture['seller'], 'seller');

    // Showing a button the action would refuse is worse than showing none.
    Livewire::test(ListShipments::class)
        ->assertTableActionVisible('ship', $fixture['shipment'])
        ->assertTableActionHidden('ship', $shipped);
});

it('offers a viewer no way to hand a parcel over', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    // Reading is the lighter bar — a warehouse hand needs the list. Declaring a
    // parcel handed over is an operational commitment that starts the delivery
    // clock, so it takes the management capability.
    $fixture = sellerWithShipment(OrganizationRole::Viewer);
    Shipment::factory()->forSeller($fixture['org']->uuid)->create();

    $this->actingAs($fixture['seller'], 'seller');

    Livewire::test(ListShipments::class)
        ->assertCanSeeTableRecords([$fixture['shipment']])
        ->assertTableActionHidden('ship', $fixture['shipment']);
});

it('gives a seller no way to create, edit or delete a parcel', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithShipment();

    // A shipment appears when an order is paid; a seller creating one would be
    // inventing an order.
    $resource = App\Modules\Shipping\Presentation\Filament\Seller\Resources\ShipmentResource::class;

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($fixture['shipment']))->toBeFalse()
        ->and($resource::canDelete($fixture['shipment']))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The carrier list
|--------------------------------------------------------------------------
*/

it('renders the seeded carriers for an admin', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = $this->actingAsAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    // The seeder ships eight; the table renders them all, which is comfortably
    // more than the one row that would leave the strict-mode guard unarmed.
    Livewire::test(ListCargoCompanies::class)
        ->assertCanSeeTableRecords(CargoCompany::query()->take(3)->get());

    expect(CargoCompany::query()->count())->toBe(8);
});

it('never deletes a carrier, only retires it', function (): void {
    $carrier = CargoCompany::query()->firstOrFail();
    $resource = App\Modules\Shipping\Presentation\Filament\Resources\CargoCompanyResource::class;

    // A shipment names its carrier and the FK restricts; withdrawal is
    // `is_active = false`, which keeps a parcel's history readable years later.
    expect($resource::canDelete($carrier))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse();
});

it('builds no tracking link for a carrier that publishes none', function (): void {
    $carrier = CargoCompany::factory()->withoutTracking()->create();

    // A link to a 404 is worse than a number rendered as text, so the caller is
    // handed the choice rather than a broken URL.
    expect($carrier->trackingUrlFor('1234567890'))->toBeNull()
        ->and(CargoCompany::query()->where('code', 'yurtici')->firstOrFail()->trackingUrlFor(null))->toBeNull();
});
