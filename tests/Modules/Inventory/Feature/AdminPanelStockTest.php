<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Presentation\Filament\Resources\StockResource;
use App\Modules\Inventory\Presentation\Filament\Resources\StockResource\Pages\ListStock;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin panel — stock oversight, which is READ and nothing else
|--------------------------------------------------------------------------
|
| Almost everything pinned here is an ABSENCE, and that is the point: this is the
| only admin oversight surface in the platform with no lever at all. Store can
| suspend, Offer can pull, Product can reject — stock can only be LOOKED AT,
| because editing a merchant's count is trading on their behalf and the ledger
| would name the wrong party (§7).
|
| The one thing that must be POSITIVELY true is that Support can reach it, since
| "the site says sold out and I have ten" is a helpdesk ticket and answering it
| needs on-hand, reserved and the movement history across sellers.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Put an already-authenticated admin into a platform role.
 *
 * Named for this file because Pest shares ONE global function namespace across
 * the whole suite — a duplicate anywhere is a fatal redeclare that only the full
 * run catches.
 */
function asStockOversightAdmin(Admin $admin, string $role = 'admin'): Admin
{
    $admin->syncRoles([config("marketplace.roles.{$role}")]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

it('lists every seller’s stock — oversight is cross-org', function (): void {
    asStockOversightAdmin($this->actingAsAdmin());

    $first = StockItem::factory()->forOrganization(1, 'org-a')->create();
    $second = StockItem::factory()->forOrganization(2, 'org-b')->create();

    // No membership anywhere: an admin is not a member of anyone's company, so
    // the gate is the `inventory.*` permission, not an org capability.
    Livewire::test(ListStock::class)->assertCanSeeTableRecords([$first, $second]);
});

it('lets Support answer the ticket this surface exists for', function (): void {
    asStockOversightAdmin($this->actingAsAdmin(), 'support');

    $item = StockItem::factory()->stocked(10, 10)->create();

    // Ten on the shelf, ten promised, none for sale — the exact shape of "the
    // site says sold out and I have ten", now legible.
    Livewire::test(ListStock::class)
        ->assertCanSeeTableRecords([$item])
        ->assertTableColumnStateSet('on_hand', 10, $item)
        ->assertTableColumnStateSet('reserved', 10, $item)
        ->assertTableColumnStateSet('available', 0, $item);
});

it('refuses an admin who holds no inventory permission', function (): void {
    // Category Manager owns the taxonomy and moderates products; a merchant's
    // stock is none of their business.
    asStockOversightAdmin($this->actingAsAdmin(), 'category_manager');

    expect(StockResource::canViewAny())->toBeFalse();
});

it('offers an operator no lever at all', function (): void {
    $admin = asStockOversightAdmin($this->actingAsAdmin());
    $item = StockItem::factory()->create();

    expect(StockResource::canCreate())->toBeFalse()
        ->and(StockResource::canEdit($item))->toBeFalse()
        ->and(StockResource::canDelete($item))->toBeFalse()
        ->and(StockResource::canDeleteAny())->toBeFalse()
        // Not even the seller's warning line: what a merchant wants to be told
        // about is theirs.
        ->and($admin->can('setLowStockThreshold', $item))->toBeFalse()
        ->and($admin->can('update', $item))->toBeFalse()
        ->and(array_keys(StockResource::getPages()))->toBe(['index', 'view']);
});

it('gives even the Super Admin no surface to edit stock from', function (): void {
    /** @var Admin $superAdmin */
    $superAdmin = $this->actingAsAdmin();
    asStockOversightAdmin($superAdmin, 'super_admin');

    $item = StockItem::factory()->create();

    /*
     * THE REFUSAL IS STRUCTURAL, NOT POLICY-SHAPED, and this test says so out
     * loud because the distinction matters.
     *
     * "Super Admin bypasses every policy" is a platform rule (CLAUDE.md), so the
     * bypass does reach `update` here — carving one ability out of it would make
     * Inventory the single module where the bypass is not what it claims. What
     * stops a merchant's count being rewritten is therefore that no code writes
     * one: no edit page, no form, no action, no route, on either panel. An
     * operation that does not exist is a stronger guarantee than a permission
     * nobody is supposed to spend.
     */
    expect($superAdmin->can('update', $item))->toBeTrue()
        ->and(StockResource::canEdit($item))->toBeFalse()
        ->and(array_keys(StockResource::getPages()))->not->toContain('edit');
});

it('finds a seller’s pools by their organization uuid', function (): void {
    asStockOversightAdmin($this->actingAsAdmin(), 'support');

    $wanted = StockItem::factory()->forOrganization(7, 'aranan-org')->create();
    $other = StockItem::factory()->forOrganization(8, 'baska-org')->create();

    // How the ticket actually starts: an agent has the seller and needs their
    // stock. The uuid is what Inventory stores — it imports no Organization to
    // resolve a legal name from (ADR-040).
    Livewire::test(ListStock::class)
        ->searchTable('aranan-org')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});
