<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Shipping\Application\Actions\MarkShippedAction;
use App\Modules\Shipping\Application\Listeners\CreateShipmentsOnPayment;
use App\Modules\Shipping\Domain\DTOs\MarkShippedDTO;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentShipped;
use App\Modules\Shipping\Domain\Exceptions\ShippingException;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use App\Shared\Support\PermissionRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| A paid order becomes a parcel (ADR-063, Shipping.md §2)
|--------------------------------------------------------------------------
|
| ONE SHIPMENT PER ORDER, created by a class-string subscription to Payment's
| event — so this file drives the REAL payment callback rather than dispatching a
| fake event. The subscription's stated cost is that a rename in Payment breaks it
| at runtime instead of at build time, and a test firing the real thing is exactly
| what bounds that.
|
| THE SELLER HAS ONE LEVER AND ONE THEY CAN NEVER REACH. "Kargoya ver" is theirs;
| `delivered` is not, because payout waits on it (ADR-064). S1 builds the first
| and refuses the second — the refusal is asserted here, not merely left absent.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A paid checkout group, one order per price given. Named for this file because
 * Pest shares ONE global function namespace.
 *
 * @param array<int, int> $prices one seller per entry, at that unit price
 *
 * @return array{payment: Payment, orders: array<int, Order>, orgs: array<int, Organization>}
 */
function shippedFixture(array $prices = [12_000]): array
{
    $customerId = 1;
    $orgs = [];

    foreach ($prices as $priceMinor) {
        $organization = Organization::factory()->create();
        $store = Store::factory()->create([
            'organization_id' => $organization->getKey(),
            'status' => StoreStatus::Active,
        ]);

        $category = Category::factory()->childOf(Category::factory()->create())->create();
        $product = Product::factory()->for($category, 'category')->published()->create([
            'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
        ]);
        $variant = ProductVariant::factory()->for($product)->create();

        $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: $priceMinor,
            stockQuantity: 20,
        ));

        app(AddCartItemAction::class)->run($customerId, 'musteri', new AddCartItemDTO(
            offerUuid: $offer->uuid,
            quantity: 1,
        ));

        $orgs[] = $organization;
    }

    $address = app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run($customerId, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    $group = $orders[0]->checkout_group_uuid;

    app(PlaceOrderAction::class)->run($group);

    return [
        'payment' => payTheGroup($group),
        'orders' => Order::query()->where('checkout_group_uuid', $group)->orderBy('id')->get()->all(),
        'orgs' => $orgs,
    ];
}

/**
 * Pay a group exactly as a verified PayTR callback would — the real path, so the
 * class-string subscription is genuinely exercised.
 */
function payTheGroup(string $group): Payment
{
    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => 1,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    app(SettlePaymentCallbackAction::class)->run([
        'merchant_oid' => str_replace('-', '', $payment->uuid),
        'status' => 'success',
        'total_amount' => (string) $total,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            str_replace('-', '', $payment->uuid).config('payment.paytr.merchant_salt').'success'.$total,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ]);

    return $payment->fresh();
}

/**
 * A seller who can act for an organization.
 */
function sellerFor(Organization $organization, OrganizationRole $role = OrganizationRole::Owner): Seller
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();

    OrganizationMember::factory()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $seller->getKey(),
        'role' => $role,
    ]);

    return $seller;
}

/*
|--------------------------------------------------------------------------
| The parcel appears when the money does
|--------------------------------------------------------------------------
*/

it('creates one pending shipment per order when a payment succeeds', function (): void {
    // Three merchants in one basket — the ADR-052 split, seen from fulfilment:
    // each seller packs their own box.
    $fixture = shippedFixture([10_000, 20_000, 5_000]);

    expect(Shipment::query()->count())->toBe(3);

    foreach ($fixture['orders'] as $index => $order) {
        $shipment = Shipment::query()->where('order_uuid', $order->uuid)->firstOrFail();

        expect($shipment->status)->toBe(ShipmentStatus::Pending)
            ->and($shipment->seller_org_uuid)->toBe($fixture['orgs'][$index]->uuid)
            // The order number is SNAPSHOTTED, so a shipment list needs no query
            // per row for a label that can never change (ADR-053).
            ->and($shipment->order_number)->toBe($order->order_number)
            ->and($shipment->shipped_at)->toBeNull();
    }
});

it('creates exactly one shipment however often the event is delivered', function (): void {
    $fixture = shippedFixture([12_000]);

    /*
     * THE EVENT REPLAYED, WHICH IS WHAT A RETRY LOOKS LIKE FROM HERE. PayTR
     * retries until it hears "OK", and a queued listener can be retried on its
     * own; either way this handler runs again for one payment. Without the guard
     * — and the UNIQUE index behind it — the seller would be handed a second
     * parcel to ship for one order.
     *
     * IT IS INVOKED WITH A PLAIN OBJECT, not with Payment's class, because that
     * is exactly what a class-string subscription hands it: the listener may rely
     * on the SHAPE and on nothing else. Building the payload here documents that
     * contract as well as testing it.
     */
    $event = (object) [
        'paymentUuid' => $fixture['payment']->uuid,
        'checkoutGroupUuid' => $fixture['payment']->checkout_group_uuid,
        'orderUuids' => [$fixture['orders'][0]->uuid],
    ];

    app(CreateShipmentsOnPayment::class)->handle($event);
    app(CreateShipmentsOnPayment::class)->handle($event);

    expect(Shipment::query()->where('order_uuid', $fixture['orders'][0]->uuid)->count())->toBe(1);
});

it('ignores an order it cannot resolve rather than failing the rest', function (): void {
    $fixture = shippedFixture([12_000]);

    // One real order and one that does not exist — the group is N independent
    // sellers, and leaving four unable to ship because the fifth is odd is worse
    // than the odd one.
    app(CreateShipmentsOnPayment::class)->handle((object) [
        'paymentUuid' => $fixture['payment']->uuid,
        'orderUuids' => [(string) Str::uuid(), $fixture['orders'][0]->uuid],
    ]);

    expect(Shipment::query()->count())->toBe(1);
});

it('gives an already-paid order its missing parcel, idempotently', function (): void {
    $fixture = shippedFixture([12_000]);

    // Simulate an order paid before this module existed — or one whose event was
    // lost to a drained queue.
    Shipment::query()->delete();

    $this->artisan('shipping:backfill')->assertSuccessful();

    expect(Shipment::query()->where('order_uuid', $fixture['orders'][0]->uuid)->count())->toBe(1);

    // Re-running is free, which is the property that makes it safe to put in a
    // deploy script.
    $this->artisan('shipping:backfill')->assertSuccessful();

    expect(Shipment::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| "Kargoya ver"
|--------------------------------------------------------------------------
*/

it('hands the parcel to a carrier and records the tracking number', function (): void {
    Event::fake([ShipmentShipped::class]);

    $fixture = shippedFixture([12_000]);
    $shipment = Shipment::query()->firstOrFail();
    $carrier = CargoCompany::query()->where('code', 'yurtici')->firstOrFail();

    $shipped = app(MarkShippedAction::class)->run(new MarkShippedDTO(
        shipmentUuid: $shipment->uuid,
        cargoCompanyUuid: $carrier->uuid,
        // Whitespace as it arrives pasted from a carrier's SMS — a link built
        // from " 123" is a 404.
        trackingNumber: '  1234567890  ',
    ));

    expect($shipped->status)->toBe(ShipmentStatus::Shipped)
        ->and($shipped->tracking_number)->toBe('1234567890')
        ->and($shipped->cargo_company_id)->toBe($carrier->getKey())
        // `shipped_at` is the clock's, never the caller's: a seller who could
        // backdate it could shorten the transit window that infers delivery —
        // and infer themselves an earlier payday.
        ->and($shipped->shipped_at)->not->toBeNull();

    // The link the buyer follows, built from the carrier's own template — which
    // is the whole reason the template is a column (§5).
    expect($shipped->fresh()->trackingUrl())->toContain('1234567890');

    Event::assertDispatched(ShipmentShipped::class);
});

it('refuses a second handover rather than overwriting the first', function (): void {
    shippedFixture([12_000]);

    $shipment = Shipment::query()->firstOrFail();
    $carrier = CargoCompany::query()->where('code', 'yurtici')->firstOrFail();
    $other = CargoCompany::query()->where('code', 'aras')->firstOrFail();

    app(MarkShippedAction::class)->run(new MarkShippedDTO($shipment->uuid, $carrier->uuid, 'AAA111'));

    /*
     * A REFUSAL, NOT A NO-OP — the opposite of how this codebase treats a retry
     * everywhere else, and deliberately. Silently accepting would either discard
     * a corrected number or silently keep the old one; both leave the buyer with
     * a link to somebody else's parcel.
     */
    expect(fn () => app(MarkShippedAction::class)->run(
        new MarkShippedDTO($shipment->uuid, $other->uuid, 'BBB222'),
    ))->toThrow(ShippingException::class);

    expect($shipment->fresh()->tracking_number)->toBe('AAA111')
        ->and($shipment->fresh()->cargo_company_id)->toBe($carrier->getKey());
});

it('refuses a carrier the operator has retired', function (): void {
    shippedFixture([12_000]);

    $shipment = Shipment::query()->firstOrFail();
    $retired = CargoCompany::factory()->inactive()->create();

    /*
     * CHECKED IN THE ACTION, not only in the form's options: a retired carrier
     * reaching here means the seller's page was open while operations withdrew
     * it, and accepting it would put a dead tracking link on a real parcel.
     */
    expect(fn () => app(MarkShippedAction::class)->run(
        new MarkShippedDTO($shipment->uuid, $retired->uuid, 'AAA111'),
    ))->toThrow(ShippingException::class);

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Pending);
});

it('404s an unknown or malformed shipment rather than exploding', function (): void {
    shippedFixture([12_000]);

    $carrier = CargoCompany::query()->where('code', 'yurtici')->firstOrFail();

    /*
     * THE UUID-CAST TRAP, EIGHTH WATCH. `shipments.uuid` is a native uuid column
     * on PostgreSQL, so `where('uuid', 'not-a-uuid')` is SQLSTATE[22P02] — a 500
     * rather than a miss — while SQLite quietly returns nothing. Resolved by
     * SHAPE before the query (ADR-059).
     */
    foreach (['not-a-uuid', 'kargo', (string) Str::uuid()] as $unknown) {
        expect(fn () => app(MarkShippedAction::class)->run(
            new MarkShippedDTO($unknown, $carrier->uuid, 'AAA111'),
        ))->toThrow(ShippingException::class);
    }

    // And a malformed CARRIER key takes the same route — it reaches a uuid column
    // too.
    $shipment = Shipment::query()->firstOrFail();

    expect(fn () => app(MarkShippedAction::class)->run(
        new MarkShippedDTO($shipment->uuid, 'not-a-uuid', 'AAA111'),
    ))->toThrow(ShippingException::class);
});

/*
|--------------------------------------------------------------------------
| Whose parcel is it
|--------------------------------------------------------------------------
*/

it('lets a seller ship only their own organization’s parcel', function (): void {
    $fixture = shippedFixture([10_000, 20_000]);

    $mine = Shipment::query()->where('seller_org_uuid', $fixture['orgs'][0]->uuid)->firstOrFail();
    $theirs = Shipment::query()->where('seller_org_uuid', $fixture['orgs'][1]->uuid)->firstOrFail();

    $seller = sellerFor($fixture['orgs'][0]);

    $this->actingAs($seller, 'seller');

    expect($seller->can('ship', $mine))->toBeTrue()
        // A cross-tenant handover would let one merchant declare another's parcel
        // sent — and start the clock that pays them.
        ->and($seller->can('ship', $theirs))->toBeFalse()
        ->and($seller->can('view', $theirs))->toBeFalse();
});

it('lets a member read what the company owes but not commit to sending it', function (): void {
    $fixture = shippedFixture([12_000]);
    $shipment = Shipment::query()->firstOrFail();

    // A warehouse hand needs the list; declaring a parcel handed over is an
    // operational commitment that starts the delivery clock.
    $viewer = sellerFor($fixture['orgs'][0], OrganizationRole::Viewer);

    $this->actingAs($viewer, 'seller');

    expect($viewer->can('view', $shipment))->toBeTrue()
        ->and($viewer->can('ship', $shipment))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The rule the module exists to keep
|--------------------------------------------------------------------------
*/

it('refuses a seller the delivery they are paid on', function (): void {
    $fixture = shippedFixture([12_000]);
    $shipment = Shipment::query()->firstOrFail();

    // Even the owner, who can do everything else for this company.
    $owner = sellerFor($fixture['orgs'][0]);

    $this->actingAs($owner, 'seller');

    /*
     * ADR-064 IN ONE ASSERTION. Delivery is not a permission somebody can be
     * trusted with: it is a fact about the physical world, and the platform has
     * exactly two honest sources for it — the buyer, and the clock (S2).
     */
    expect($owner->can('deliver', $shipment))->toBeFalse();
});

it('has no operation anywhere in S1 that could set a delivery date', function (): void {
    /*
     * THE GUARANTEE THAT ACTUALLY HOLDS, and the reason it is asserted this way.
     * `Gate::before()` grants a Super Admin every ability before any policy runs
     * — that is a platform rule (CLAUDE.md), and a module carving itself out of
     * it would make this the one place the bypass is not what it says. So the
     * refusal that matters is the ABSENCE of the operation: no permission to
     * grant, and no action to call.
     *
     * `InventoryPolicy::update()` states the same reasoning for stock: an
     * operation that does not exist is stronger than a permission nobody can
     * spend.
     */
    /** @var array<int, string> $adminAbilities */
    $adminAbilities = PermissionRegistry::all()['admin'] ?? [];

    // No permission to grant…
    expect($adminAbilities)->not->toContain('shipment.deliver')
        ->and($adminAbilities)->toContain('shipment.view_any');

    // Nothing in the module writes the columns, so a paid-and-shipped parcel
    // stays undelivered until S2 exists.
    $fixture = shippedFixture([12_000]);
    $shipment = Shipment::query()->firstOrFail();
    $carrier = CargoCompany::query()->where('code', 'yurtici')->firstOrFail();

    app(MarkShippedAction::class)->run(new MarkShippedDTO($shipment->uuid, $carrier->uuid, 'AAA111'));

    // …and no operation that writes the columns, so a shipped parcel stays
    // undelivered until S2 exists.
    expect($shipment->fresh()->delivered_at)->toBeNull()
        ->and($shipment->fresh()->delivered_via)->toBeNull();
});
