<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Order\Domain\Enums\ReturnRequestStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Order\Domain\Models\ReturnRequest;
use App\Modules\Order\Presentation\Filament\Seller\Resources\ReturnRequestResource;
use App\Modules\Order\Presentation\Filament\Seller\Resources\ReturnRequestResource\Pages\ListReturnRequests;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| R5 — the seller's return inbox (ADR-073)
|--------------------------------------------------------------------------
|
| **THREE BUTTONS, AND ONLY THE THIRD SPENDS ANYTHING.** That asymmetry is what
| this file pins: approving hands the buyer a return code and moves no money;
| completing refunds. A regression that merged the two would refund every return
| the moment a seller agreed to look at it — which is precisely the behaviour
| ADR-073 removed.
|
| The tenancy wall is the other half: a return request carries only the order's
| uuid, so the scope goes through the order's store. A failure here is a
| cross-tenant leak, not a UI bug.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A seller, their live store, an order placed with them, and a return request
 * against it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, order: Order, request: ReturnRequest, line: OrderLine}
 */
function sellerWithReturn(
    ReturnRequestStatus $status = ReturnRequestStatus::Requested,
    OrganizationRole $role = OrganizationRole::Owner,
): array {
    /*
     * `owner()`, NOT a bare factory: `Seller::factory()->create()` assigns no
     * role, and answering a return needs the `order.decide_return` permission
     * (ADR-073) on top of membership. A roleless seller is not a shape production
     * has — registration assigns the role — so a fixture without it would test a
     * user who cannot exist.
     */
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
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

    $line = OrderLine::factory()->for($order)->priced(12_000, 2, '0.2000')
        ->labelled('Pamuklu Tişört', 'M / Siyah')->create();

    $request = ReturnRequest::factory()
        ->forOrder($order)
        ->lines([$line->uuid => 1])
        ->state(['status' => $status])
        ->create();

    return [
        'seller' => $seller,
        'org' => $organization,
        'order' => $order,
        'request' => $request->fresh(),
        'line' => $line,
    ];
}

/*
|--------------------------------------------------------------------------
| The wall
|--------------------------------------------------------------------------
*/

it('shows a seller only the returns for their own orders', function (): void {
    $mine = sellerWithReturn();
    $theirs = sellerWithReturn();

    $this->actingAsSeller($mine['seller']);

    /*
     * TWO ROWS EXIST AND ONE IS VISIBLE. The request holds only the order's uuid,
     * so the scope goes through the order's store — `OrderResource`'s own wall,
     * borrowed rather than rebuilt, because two spellings of "which orders are
     * mine" is how one of them ends up wider.
     */
    Livewire::test(ListReturnRequests::class)
        ->assertCanSeeTableRecords([$mine['request']])
        ->assertCanNotSeeTableRecords([$theirs['request']]);
});

it('offers no create, edit or delete', function (): void {
    $fixture = sellerWithReturn();
    $this->actingAsSeller($fixture['seller']);

    /*
     * A seller inventing a buyer's return would be refunding on their behalf; an
     * answer is a decision rather than a draft; and a rejection somebody wants to
     * erase is exactly the row a dispute needs.
     */
    expect(ReturnRequestResource::canCreate())->toBeFalse()
        ->and(ReturnRequestResource::canEdit($fixture['request']))->toBeFalse()
        ->and(ReturnRequestResource::canDelete($fixture['request']))->toBeFalse()
        ->and(ReturnRequestResource::canDeleteAny())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Approve — an instruction, not a payment
|--------------------------------------------------------------------------
*/

it('sends a return code on approval and refunds nothing', function (): void {
    $fixture = sellerWithReturn();
    $this->actingAsSeller($fixture['seller']);

    $carrier = CargoCompany::query()->firstOrFail();

    Livewire::test(ListReturnRequests::class)
        ->callTableAction('approve', $fixture['request'], [
            'return_code' => 'IADE-31337',
            'cargo_company_uuid' => $carrier->uuid,
        ])
        ->assertHasNoTableActionErrors();

    $request = $fixture['request']->fresh();

    expect($request->status)->toBe(ReturnRequestStatus::Approved)
        ->and($request->return_code)->toBe('IADE-31337')
        ->and($request->cargo_company_uuid)->toBe($carrier->uuid)
        /*
         * **THE ASSERTION THAT SEPARATES THIS INBOX FROM THE CANCELLATION ONE.**
         * There, approving refunds. Here it cannot: the parcel is still in the
         * buyer's hands.
         */
        ->and($request->completed_at)->toBeNull();
});

it('demands a return code — approval without one is not an instruction', function (): void {
    $fixture = sellerWithReturn();
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(ListReturnRequests::class)
        ->callTableAction('approve', $fixture['request'], [
            'return_code' => '',
            'cargo_company_uuid' => null,
        ])
        ->assertHasTableActionErrors(['return_code', 'cargo_company_uuid']);

    expect($fixture['request']->fresh()->status)->toBe(ReturnRequestStatus::Requested);
});

it('demands a sentence when rejecting', function (): void {
    $fixture = sellerWithReturn();
    $this->actingAsSeller($fixture['seller']);

    // Refusing somebody's return without a word is the support ticket the field
    // exists to prevent. The column is nullable; this surface is not.
    Livewire::test(ListReturnRequests::class)
        ->callTableAction('reject', $fixture['request'], ['decision_reason' => ''])
        ->assertHasTableActionErrors(['decision_reason']);

    Livewire::test(ListReturnRequests::class)
        ->callTableAction('reject', $fixture['request'], ['decision_reason' => 'Ürün kullanılmış'])
        ->assertHasNoTableActionErrors();

    expect($fixture['request']->fresh()->status)->toBe(ReturnRequestStatus::Rejected)
        ->and($fixture['request']->fresh()->decision_reason)->toBe('Ürün kullanılmış');
});

/*
|--------------------------------------------------------------------------
| Complete — the only button that spends
|--------------------------------------------------------------------------
*/

it('hides "İadeyi tamamla" until the seller has approved', function (): void {
    $fixture = sellerWithReturn(ReturnRequestStatus::Requested);
    $this->actingAsSeller($fixture['seller']);

    /*
     * COMPLETING A REQUEST NOBODY AGREED TO would refund a parcel still in the
     * buyer's hallway. The action refuses anyway — this keeps the button off the
     * screen rather than letting it throw when pressed.
     */
    Livewire::test(ListReturnRequests::class)
        ->assertTableActionHidden('complete', $fixture['request'])
        ->assertTableActionVisible('approve', $fixture['request']);
});

it('shows "İadeyi tamamla" once approved, and hides the answers', function (): void {
    $fixture = sellerWithReturn(ReturnRequestStatus::Approved);
    $this->actingAsSeller($fixture['seller']);

    Livewire::test(ListReturnRequests::class)
        ->assertTableActionVisible('complete', $fixture['request'])
        // An approved return cannot be re-approved or withdrawn: the buyer has a
        // code and may already have posted the parcel.
        ->assertTableActionHidden('approve', $fixture['request'])
        ->assertTableActionHidden('reject', $fixture['request']);
});

it('warns rather than 500s when the refund cannot go through', function (): void {
    $fixture = sellerWithReturn(ReturnRequestStatus::Approved);
    $this->actingAsSeller($fixture['seller']);

    /*
     * **THE ORDER HAS NO PAYMENT AND NO DELIVERY**, so the Core port refuses —
     * exactly as it would if the PSP said no or the window had closed while the
     * parcel travelled. A stack trace on the button that returns somebody's money
     * is the worst place on the platform for one, so the surface catches it.
     */
    Livewire::test(ListReturnRequests::class)
        ->callTableAction('complete', $fixture['request'])
        ->assertHasNoTableActionErrors();

    // AND THE REQUEST IS UNCHANGED — refund first, stamp second, so a failure
    // leaves it `Approved` and the seller may press again once the cause is fixed.
    expect($fixture['request']->fresh()->status)->toBe(ReturnRequestStatus::Approved)
        ->and($fixture['request']->fresh()->completed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Who may answer
|--------------------------------------------------------------------------
*/

it('lets a Seller Employee answer — receiving returns is delegable', function (): void {
    $fixture = sellerWithReturn();

    /** @var Seller $employee */
    $employee = Seller::factory()->create();
    $employee->syncRoles([config('marketplace.roles.seller_employee')]);

    OrganizationMember::factory()->for($fixture['org'])->role(OrganizationRole::Support)
        ->create(['user_id' => $employee->getKey()]);

    $this->actingAsSeller($employee);

    /*
     * THE PERSON WHO OPENS THE PARCEL IS THE PERSON WHO KNOWS whether it may be
     * accepted (ADR-073), the same reasoning that made answering questions
     * delegable. `order.decide_return` is granted explicitly in the seeder
     * because it carries real money.
     */
    Livewire::test(ListReturnRequests::class)
        ->assertCanSeeTableRecords([$fixture['request']])
        ->assertTableActionVisible('approve', $fixture['request']);
});

it('counts approved returns in the badge too', function (): void {
    $fixture = sellerWithReturn(ReturnRequestStatus::Approved);
    $this->actingAsSeller($fixture['seller']);

    /*
     * **NOT JUST THE UNANSWERED ONES**, which is where this badge differs from
     * the cancellation inbox's. An approved return is a parcel on its way here; a
     * badge that dropped to zero on approval would let it be forgotten with the
     * buyer's money still taken.
     */
    expect(ReturnRequestResource::getNavigationBadge())->toBe('1');

    app(App\Modules\Order\Application\Actions\RejectReturnAction::class);

    $fixture['request']->forceFill(['status' => ReturnRequestStatus::Completed])->save();

    expect(ReturnRequestResource::getNavigationBadge())->toBeNull();
});
