<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Order\Presentation\Filament\Resources\OrderResource as AdminOrderResource;
use App\Modules\Order\Presentation\Filament\Resources\OrderResource\Pages\ListOrders as AdminListOrders;
use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource as SellerOrderResource;
use App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages\ListOrders as SellerListOrders;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The two operator surfaces (§4, §7)
|--------------------------------------------------------------------------
|
| ONE SELLER'S HALF, AND THE WHOLE PURCHASE — the split (ADR-052) read from both
| ends. A merchant sees their own order and no part of what the customer bought
| elsewhere; an admin is the only actor who can pull up every order of one
| checkout group, because "I paid for three things and two arrived" is a question
| about the group.
|
| The tenancy wall on the seller side is unusual and worth pinning: an order
| carries the seller as a UUID while `OrganizationAuthorizationContract` answers
| memberships in internal IDS, so the scope goes through
| `StoreQueryContract::liveStoresForOrganization()`. A failure here is a
| cross-tenant leak, not a UI bug.
|
| Neither surface can EDIT anything. The lines are immutable and the totals were
| written once (ADR-053) — cancel is the only lever, and on the admin side it is
| held back from Support.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who belongs to a company with a live store, plus an order placed with
 * them.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, store: Store, order: Order}
 */
function sellerWithOrder(OrganizationRole $role = OrganizationRole::Owner): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role($role)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $order = Order::factory()
        ->forSeller($organization->uuid, $store->uuid)
        ->totalling(24_000, 4_000)
        ->create();

    OrderLine::factory()->for($order)->priced(12_000, 2, '0.2000')
        ->labelled('Pamuklu Tişört', 'M / Siyah')->create();

    return ['seller' => $seller, 'org' => $organization, 'store' => $store, 'order' => $order];
}

/**
 * Put an already-authenticated admin into a platform role.
 */
function asOrderOversightAdmin(Admin $admin, string $role = 'admin'): Admin
{
    $admin->syncRoles([config("marketplace.roles.{$role}")]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

/*
|--------------------------------------------------------------------------
| Seller — "Siparişlerim"
|--------------------------------------------------------------------------
*/

it('shows a seller only the orders placed with them', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $mine = sellerWithOrder();
    $theirs = sellerWithOrder();

    $this->actingAsSeller($mine['seller']);

    /*
     * The wall goes through the STORE, because the order holds the seller as a
     * uuid and the Core authorization contract answers in internal ids. A failure
     * here is a cross-tenant leak.
     */
    Livewire::test(SellerListOrders::class)
        ->assertCanSeeTableRecords([$mine['order']])
        ->assertCanNotSeeTableRecords([$theirs['order']]);
});

it('shows a company member the orders even without the management capability', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    // Reading is the lighter bar (§7): a warehouse or support member has to see
    // what they are expected to pack and answer for.
    $fixture = sellerWithOrder(OrganizationRole::Viewer);
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(SellerListOrders::class)->assertCanSeeTableRecords([$fixture['order']]);
});

it('renders a seller’s totals as money, not as minor units', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithOrder();
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(SellerListOrders::class)
        ->assertTableColumnStateSet('grand_total_minor', '240.00 '.$fixture['order']->currency->code, $fixture['order']);
});

it('offers a seller no way to create, edit or delete an order', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithOrder();
    $this->actingAsSeller($fixture['seller']);

    /*
     * The lines are immutable and the totals were written once (ADR-053). A seller
     * who needs a different order cancels this one and the customer places
     * another, which leaves both facts on the record.
     */
    expect(SellerOrderResource::canCreate())->toBeFalse()
        ->and(SellerOrderResource::canEdit($fixture['order']))->toBeFalse()
        ->and(SellerOrderResource::canDelete($fixture['order']))->toBeFalse()
        ->and(array_keys(SellerOrderResource::getPages()))->toBe(['index', 'view']);
});

it('lets a seller cancel an order placed with them, with a reason', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithOrder();
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(SellerListOrders::class)
        ->callTableAction('cancel', $fixture['order'], ['reason' => 'Stokta kalmadı']);

    // Refusing an order is a real merchant decision; the alternative is a support
    // ticket for every one. The reason is required and the customer is shown it.
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($fixture['order']->fresh()->cancellation_reason)->toBe('Stokta kalmadı');
});

it('gives a seller no way to cancel another seller’s order', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $mine = sellerWithOrder();
    $theirs = sellerWithOrder();

    $this->actingAsSeller($mine['seller']);

    expect($mine['seller']->can('cancel', $theirs['order']))->toBeFalse()
        ->and($mine['seller']->can('view', $theirs['order']))->toBeFalse();
});

it('shows the seller what was bought, as it was bought', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('seller'));

    $fixture = sellerWithOrder();
    $this->actingAsSeller($fixture['seller']);

    $line = $fixture['order']->lines()->sole();

    // The snapshot (ADR-053) — the name the customer actually bought under, and
    // read-only, because a financial record that can be edited is not one.
    Livewire::test(\App\Modules\Order\Presentation\Filament\RelationManagers\LinesRelationManager::class, [
        'ownerRecord' => $fixture['order'],
        'pageClass' => \App\Modules\Order\Presentation\Filament\Seller\Resources\OrderResource\Pages\ViewOrder::class,
    ])
        ->assertCanSeeTableRecords([$line])
        ->assertTableColumnStateSet('product_title', 'Pamuklu Tişört', $line)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

/*
|--------------------------------------------------------------------------
| Admin — oversight
|--------------------------------------------------------------------------
*/

it('shows an admin every seller’s orders', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    asOrderOversightAdmin($this->actingAsAdmin());

    $first = Order::factory()->forSeller('satici-a')->create();
    $second = Order::factory()->forSeller('satici-b')->create();

    Livewire::test(AdminListOrders::class)->assertCanSeeTableRecords([$first, $second]);
});

it('reassembles a purchase from its checkout group — the thing only this surface can do', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    asOrderOversightAdmin($this->actingAsAdmin(), 'support');

    $group = (string) Str::uuid();
    $first = Order::factory()->inCheckoutGroup($group)->forSeller('satici-a')->create();
    $second = Order::factory()->inCheckoutGroup($group)->forSeller('satici-b')->create();
    $unrelated = Order::factory()->create();

    /*
     * "I paid for three things and two arrived" is a question about the GROUP.
     * Everywhere else the split is a feature; here it has to be undoable, and
     * pasting the group uuid into the search is how.
     */
    Livewire::test(AdminListOrders::class)
        ->searchTable($group)
        ->assertCanSeeTableRecords([$first, $second])
        ->assertCanNotSeeTableRecords([$unrelated]);
});

it('lets Support read orders but not cancel one', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $support = asOrderOversightAdmin($this->actingAsAdmin(), 'support');

    $order = Order::factory()->create();

    /*
     * "Where is my order?" is the most common ticket a marketplace takes, and
     * answering it needs the order. CANCELLING it releases or strands somebody's
     * stock and creates a refund obligation once Payment exists — an Admin
     * decision, not a helpdesk one.
     */
    expect(AdminOrderResource::canViewAny())->toBeTrue()
        ->and($support->can('view', $order))->toBeTrue()
        ->and($support->can('cancel', $order))->toBeFalse();
});

it('lets an admin cancel, and refuses the ones already cancelled', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = asOrderOversightAdmin($this->actingAsAdmin());

    $live = Order::factory()->create();
    $cancelled = Order::factory()->cancelled('Zaten iptal')->create();

    expect($admin->can('cancel', $live))->toBeTrue()
        // Denied at the policy so the UI hides the button — while the ACTION
        // still treats a repeat as a silent no-op.
        ->and($admin->can('cancel', $cancelled))->toBeFalse();

    Livewire::test(AdminListOrders::class)
        ->callTableAction('cancel', $live, ['reason' => 'Sahte sipariş']);

    expect($live->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('refuses an admin who holds no order permission', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Category Manager owns the taxonomy and moderates products; somebody's
    // purchase is none of their business.
    asOrderOversightAdmin($this->actingAsAdmin(), 'category_manager');

    expect(AdminOrderResource::canViewAny())->toBeFalse();
});

it('offers an operator no way to rewrite an order', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    asOrderOversightAdmin($this->actingAsAdmin());

    $order = Order::factory()->create();

    // An operator adjusting an order's money would be rewriting a financial
    // record with the audit entry naming the wrong party.
    expect(AdminOrderResource::canCreate())->toBeFalse()
        ->and(AdminOrderResource::canEdit($order))->toBeFalse()
        ->and(AdminOrderResource::canDelete($order))->toBeFalse()
        ->and(array_keys(AdminOrderResource::getPages()))->toBe(['index', 'view']);
});

it('never lets an operator place somebody’s order for them', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = asOrderOversightAdmin($this->actingAsAdmin(), 'super_admin');

    $order = Order::factory()->create();

    /*
     * Placing commits stock and creates a debt in the customer's NAME (ADR-054).
     * An operator doing that on someone's behalf is not oversight, it is buying
     * for them — so the ability is refused even before the Super Admin bypass is
     * considered, by there being no surface that calls it.
     */
    expect(AdminOrderResource::getPages())->not->toHaveKey('place');

    // The seller has none either.
    expect(SellerOrderResource::getPages())->not->toHaveKey('place');
});
