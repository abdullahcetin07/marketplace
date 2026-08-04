<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Inventory\Presentation\Filament\RelationManagers\MovementsRelationManager;
use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource;
use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource\Pages\ListStock;
use App\Modules\Inventory\Presentation\Filament\Seller\Resources\StockResource\Pages\ViewStock;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — "Stoğum"
|--------------------------------------------------------------------------
|
| Four things are worth pinning, and three of them are ABSENCES:
|
|  1. THE TENANCY WALL (ADR-030). Another seller's pools are not in the query. A
|     failure here is a cross-tenant leak, not a UI bug.
|  2. AVAILABLE IS THE POINT. It is computed on read (ADR-048) and is the one
|     number the Offer form cannot show — so it is asserted as rendered state,
|     not merely as a model method.
|  3. NO EDIT SURFACE. The count is entered on the Offer form; a second front
|     door here would let the same number be written two ways.
|  4. The ONE write is the low-stock threshold, and it records NO MOVEMENT —
|     because the ledger records where stock went and a warning line is not
|     stock going anywhere.
|
| The panel is set explicitly because a Livewire test has no panel middleware to
| do it.
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A seller with one company, and a stock pool in it.
 *
 * Named for this file because Pest shares ONE global function namespace across
 * the whole suite.
 *
 * @return array{seller: Seller, org: Organization}
 */
function sellerWithStock(OrganizationRole $role = OrganizationRole::Owner): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role($role)
        ->create(['user_id' => $seller->getKey()]);

    return ['seller' => $seller, 'org' => $organization];
}

/**
 * @param array{seller: Seller, org: Organization} $fixture
 */
function stockPoolFor(array $fixture, int $onHand = 10, int $reserved = 0): StockItem
{
    return StockItem::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->stocked($onHand, $reserved)
        ->create();
}

it('lists only the acting seller’s own stock', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $mine = stockPoolFor($fixture);
    // Another merchant's pool, in a company this seller has no membership of.
    $theirs = StockItem::factory()->create();

    Livewire::test(ListStock::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('shows a member of the company the stock even without the management capability', function (): void {
    // Reading is the lighter bar (§7): a Viewer sees what the company holds and
    // changes nothing.
    $fixture = sellerWithStock(OrganizationRole::Viewer);
    $this->actingAsSeller($fixture['seller']);

    $mine = stockPoolFor($fixture);

    Livewire::test(ListStock::class)->assertCanSeeTableRecords([$mine]);
});

it('shows the subtraction the offer form cannot: ten on hand, three reserved, seven for sale', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $item = stockPoolFor($fixture, onHand: 10, reserved: 3);

    /*
     * THE WHOLE REASON THIS PAGE EXISTS. `Offer.stock_quantity` says 10 and is not
     * wrong — but three of those are promised to somebody's checkout, and no
     * column on the Offer could say so.
     */
    Livewire::test(ListStock::class)
        ->assertTableColumnStateSet('on_hand', 10, $item)
        ->assertTableColumnStateSet('reserved', 3, $item)
        ->assertTableColumnStateSet('available', 7, $item);
});

it('offers a seller no way to edit, create or delete stock', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $item = stockPoolFor($fixture);

    // Not styling — the resource has no edit page and refuses the verbs, so a
    // future form has nowhere to attach itself (§7).
    expect(StockResource::canCreate())->toBeFalse()
        ->and(StockResource::canEdit($item))->toBeFalse()
        ->and(StockResource::canDelete($item))->toBeFalse()
        ->and(StockResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(StockResource::getPages()))->toBe(['index', 'view']);
});

it('sets the low-stock threshold — and writes no movement doing it', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $item = stockPoolFor($fixture);

    Livewire::test(ListStock::class)
        ->callTableAction('set_threshold', $item, ['threshold' => 4]);

    expect($item->fresh()->low_stock_threshold)->toBe(4)
        // A preference, not a count. Putting two zero deltas into the ledger
        // would pollute the one place a seller goes to see where stock went.
        ->and(StockMovement::query()->where('stock_item_id', $item->getKey())->count())->toBe(0);
});

it('clears the threshold when the seller empties the field', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $item = StockItem::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->withLowStockThreshold(5)
        ->create(['low_stock_notified' => true]);

    Livewire::test(ListStock::class)
        ->callTableAction('set_threshold', $item, ['threshold' => null]);

    // "Stop telling me" is a real request, distinct from a threshold of zero —
    // and it re-arms the flag so a later line is not silenced by this one.
    expect($item->fresh()->low_stock_threshold)->toBeNull()
        ->and($item->fresh()->low_stock_notified)->toBeFalse();
});

it('hides the threshold action from a member who cannot manage the company', function (): void {
    $fixture = sellerWithStock(OrganizationRole::Viewer);
    $this->actingAsSeller($fixture['seller']);

    stockPoolFor($fixture);

    // A Viewer reads; what the company gets warned about is an operational
    // decision (§7).
    Livewire::test(ListStock::class)->assertTableActionHidden('set_threshold', StockItem::query()->sole());
});

it('filters to the pools that need attention', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $low = StockItem::factory()
        ->forOrganization($fixture['org']->getKey(), $fixture['org']->uuid)
        ->stocked(4)->withLowStockThreshold(5)->create();
    $sold = stockPoolFor($fixture, onHand: 2, reserved: 2);
    $healthy = stockPoolFor($fixture, onHand: 50);

    // The threshold is compared against on_hand − reserved in SQL, because
    // availability is not a column.
    Livewire::test(ListStock::class)
        ->filterTable('low_stock')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$healthy]);

    // Fully reserved counts as out of stock: the units exist and none is for
    // sale.
    Livewire::test(ListStock::class)
        ->filterTable('out_of_stock')
        ->assertCanSeeTableRecords([$sold])
        ->assertCanNotSeeTableRecords([$healthy, $low]);
});

it('shows the movement ledger for one pool, newest first and read-only', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $item = stockPoolFor($fixture);

    $entry = StockMovement::factory()->for($item, 'stockItem')->create([
        'type' => StockMovementType::SellerAdjustment,
        'on_hand_delta' => 10,
        'reserved_delta' => 0,
    ]);

    // The answer to "the system says 3 and I never sold that many" (ADR-050).
    Livewire::test(MovementsRelationManager::class, [
        'ownerRecord' => $item,
        'pageClass' => ViewStock::class,
    ])
        ->assertCanSeeTableRecords([$entry])
        // The ledger is evidence, so there is nothing here to change it with.
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');
});

it('never shows one seller another seller’s pool by uuid', function (): void {
    $fixture = sellerWithStock();
    $this->actingAsSeller($fixture['seller']);

    $theirs = StockItem::factory()->create();

    // The wall is the query AND the policy: the page cannot resolve a record
    // outside the seller's own organizations at all, and the policy would refuse
    // it even if it could (§7).
    expect(StockResource::canView($theirs))->toBeFalse();

    expect(fn () => Livewire::test(ViewStock::class, ['record' => $theirs->getRouteKey()]))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
