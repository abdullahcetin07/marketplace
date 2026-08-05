<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Shipping\Application\Actions\ConfirmReceiptAction;
use App\Modules\Shipping\Application\Jobs\SweepTransitDeliveriesJob;
use App\Modules\Shipping\Domain\Enums\DeliveredVia;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Shipping\Domain\Events\ShipmentDelivered;
use App\Modules\Shipping\Domain\Exceptions\ShippingException;
use App\Modules\Shipping\Domain\Models\CargoCompany;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Delivery is inferred, never asserted (ADR-064, Shipping.md §3)
|--------------------------------------------------------------------------
|
| **THE WEAK POINT OF A MANUAL FULFILMENT MODEL IS "DELIVERED"**, because the
| seller is paid on it. So the platform has exactly two honest sources, and this
| file is the proof that it has no third:
|
|   THE BUYER    presses "Teslim aldım" — the strongest signal there is, because
|                the person holding the box said so.
|   THE CLOCK    `shipped_at + transit_days` elapses and the sweep infers it.
|
| Both write `delivered_via`, because an observed delivery and a guessed one are
| worth different amounts in a dispute and a single timestamp could not say which
| it was.
|
| Both emit `ShipmentDelivered` — the event this whole module exists for. Order
| moves its own fulfilment state on it by class-string; from S3 Payment starts a
| payout clock and a return window the same way.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A customer's order with a parcel already in transit. Named for this file
 * because Pest shares ONE global function namespace.
 *
 * @return array{customer: Customer, org: Organization, order: Order, shipment: Shipment}
 */
function inTransitFixture(): array
{
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $organization = Organization::factory()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'selling_org_uuid' => $organization->uuid,
        'status' => OrderStatus::Paid,
    ]);

    $shipment = Shipment::factory()
        ->forSeller($organization->uuid)
        ->forOrder($order->uuid, $order->order_number)
        ->shipped()
        ->create(['cargo_company_id' => CargoCompany::query()->where('code', 'yurtici')->value('id')]);

    return ['customer' => $customer, 'org' => $organization, 'order' => $order, 'shipment' => $shipment];
}

/*
|--------------------------------------------------------------------------
| The buyer confirms
|--------------------------------------------------------------------------
*/

it('marks a parcel delivered when the buyer says it arrived', function (): void {
    Event::fake([ShipmentDelivered::class]);

    $fixture = inTransitFixture();

    app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $fixture['customer']->getKey());

    $shipment = $fixture['shipment']->fresh();

    expect($shipment->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->delivered_at)->not->toBeNull()
        /*
         * THE PROVENANCE, WHICH IS THE POINT OF THE COLUMN. This is the strongest
         * signal the platform has, and a support agent arbitrating "I never got
         * it" needs to be able to tell it from the clock running out.
         */
        ->and($shipment->delivered_via)->toBe(DeliveredVia::Buyer);

    Event::assertDispatched(ShipmentDelivered::class, function (ShipmentDelivered $event) use ($fixture): bool {
        // The event carries the date FROM THE ROW, so a queued listener computing
        // a payout date cannot get a different answer when the queue is behind.
        return $event->orderUuid === $fixture['order']->uuid
            && $event->sellerOrgUuid === $fixture['org']->uuid
            && $event->deliveredVia === 'buyer'
            && $event->deliveredAt !== '';
    });
});

it('lets a buyer tap the button twice without moving the date', function (): void {
    $fixture = inTransitFixture();

    app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $fixture['customer']->getKey());

    $first = $fixture['shipment']->fresh()->delivered_at;

    $this->travel(2)->hours();

    app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $fixture['customer']->getKey());

    /*
     * A NO-OP, NOT A REFUSAL — the opposite of "kargoya ver", and for the opposite
     * reason: there is nothing to lose, the buyer is telling us something we
     * know. What must NOT happen is the date moving, because that date is a
     * payout schedule and a return deadline and re-stamping it silently extends
     * both.
     */
    expect($fixture['shipment']->fresh()->delivered_at->toIso8601String())
        ->toBe($first->toIso8601String());
});

it('refuses a buyer somebody else’s order', function (): void {
    $fixture = inTransitFixture();
    $stranger = Customer::factory()->create();

    /*
     * ONE ANSWER FOR "not yours" AND "does not exist" — the rule every public
     * surface here keeps. Otherwise anyone could discover which order uuids are
     * real by watching which error comes back.
     */
    expect(fn () => app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $stranger->getKey()))
        ->toThrow(ShippingException::class);

    expect($fixture['shipment']->fresh()->status)->toBe(ShipmentStatus::Shipped)
        ->and($fixture['shipment']->fresh()->delivered_at)->toBeNull();
});

it('refuses to confirm a parcel that was never handed over', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'status' => OrderStatus::Paid,
    ]);

    // Still `pending`: nobody gave it to a carrier, so it cannot have arrived.
    $shipment = Shipment::factory()->forOrder($order->uuid, $order->order_number)->create();

    app(ConfirmReceiptAction::class)->run($order->uuid, $customer->getKey());

    expect($shipment->fresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($shipment->fresh()->delivered_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The clock runs out
|--------------------------------------------------------------------------
*/

it('infers delivery once the transit window has elapsed', function (): void {
    Event::fake([ShipmentDelivered::class]);

    $fixture = inTransitFixture();

    // The factory ships it a day ago; the default window is three.
    app(SweepTransitDeliveriesJob::class)->handle();

    expect($fixture['shipment']->fresh()->status)->toBe(ShipmentStatus::Shipped);

    Event::assertNotDispatched(ShipmentDelivered::class);

    $this->travel(4)->days();

    app(SweepTransitDeliveriesJob::class)->handle();

    $shipment = $fixture['shipment']->fresh();

    expect($shipment->status)->toBe(ShipmentStatus::Delivered)
        /*
         * THE WEAKEST SIGNAL IN THE MODULE, AND IT SAYS SO. Nobody confirmed
         * anything — a clock decided — and that is exactly what a buyer disputing
         * "I never got it" needs to be able to see.
         */
        ->and($shipment->delivered_via)->toBe(DeliveredVia::TransitSweep);

    Event::assertDispatched(ShipmentDelivered::class);
});

it('reads the window from settings, so an operator can change it without a release', function (): void {
    // The settings table is what production has; `seedPlatform()` deliberately
    // does not seed it for every test, so this one asks for it.
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $fixture = inTransitFixture();

    // The parcel shipped a day ago and the default window is three, so nothing is
    // due — until operations decides one day is enough.
    app(SweepTransitDeliveriesJob::class)->handle();

    expect($fixture['shipment']->fresh()->status)->toBe(ShipmentStatus::Shipped);

    settings()->set('shipping.transit_days', 1);

    app(SweepTransitDeliveriesJob::class)->handle();

    expect($fixture['shipment']->fresh()->status)->toBe(ShipmentStatus::Delivered);
});

it('leaves an already-delivered parcel exactly where it is', function (): void {
    $fixture = inTransitFixture();

    app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $fixture['customer']->getKey());

    $confirmed = $fixture['shipment']->fresh();

    $this->travel(10)->days();

    Event::fake([ShipmentDelivered::class]);

    app(SweepTransitDeliveriesJob::class)->handle();

    $after = $fixture['shipment']->fresh();

    /*
     * IDEMPOTENCE THAT MATTERS TO SOMEBODY'S MONEY. A sweep that re-stamped an
     * already-delivered parcel would push its payout date out by however long the
     * platform took to notice — and would overwrite a BUYER-confirmed delivery
     * with a guess, which is the more expensive half.
     */
    expect($after->delivered_at->toIso8601String())->toBe($confirmed->delivered_at->toIso8601String())
        ->and($after->delivered_via)->toBe(DeliveredVia::Buyer);

    Event::assertNotDispatched(ShipmentDelivered::class);
});

it('never sweeps a parcel that was never shipped', function (): void {
    $pending = Shipment::factory()->create();

    $this->travel(30)->days();

    app(SweepTransitDeliveriesJob::class)->handle();

    // `shipped_at` is null, and `null <= now()` is not the comparison anybody
    // intended — a pending parcel would otherwise be swept on its first pass.
    expect($pending->fresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($pending->fresh()->delivered_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Order hears about it
|--------------------------------------------------------------------------
*/

it('moves the order to delivered, from Order’s own listener', function (): void {
    $fixture = inTransitFixture();

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid);

    app(ConfirmReceiptAction::class)->run($fixture['order']->uuid, $fixture['customer']->getKey());

    /*
     * SHIPPING DID NOT SET THIS. It announced `ShipmentDelivered`; Order
     * subscribes by class-string and moves its own state machine — the same
     * division of labour as `PaymentSucceeded`. A module setting another's status
     * is the boundary failing at its most tempting point.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Delivered);
});

/*
|--------------------------------------------------------------------------
| The third source that does not exist
|--------------------------------------------------------------------------
*/

it('gives the seller no way to reach delivery, whatever they hold', function (): void {
    $fixture = inTransitFixture();

    /** @var Seller $seller */
    $seller = Seller::factory()->create();

    OrganizationMember::factory()->create([
        'organization_id' => $fixture['org']->getKey(),
        'user_id' => $seller->getKey(),
        'role' => OrganizationRole::Owner,
    ]);

    $this->actingAs($seller, 'seller');

    /*
     * ADR-064. The policy denies it — but the guarantee that actually holds is
     * that there is nothing to call: no action takes a seller, no route accepts
     * one, and the two things that CAN deliver both need somebody the seller is
     * not (the buyer, or the clock).
     */
    expect($seller->can('deliver', $fixture['shipment']))->toBeFalse()
        ->and($fixture['shipment']->fresh()->delivered_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The buyer's endpoints
|--------------------------------------------------------------------------
*/

it('shows a buyer their parcel and takes their confirmation', function (): void {
    $fixture = inTransitFixture();

    $this->actingAs($fixture['customer'], 'customer');

    $shown = $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/shipment")
        ->assertOk()
        ->json('data');

    expect($shown['status'])->toBe('shipped')
        ->and($shown['carrier'])->toBe('Yurtiçi Kargo')
        // Built from the carrier's template — the reason the template is a column.
        ->and($shown['tracking_url'])->toContain($shown['tracking_number'])
        // Decided once, on the server: a client that derived it would eventually
        // show a button the API refuses.
        ->and($shown['can_confirm_receipt'])->toBeTrue();

    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/shipment/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.delivered_via', 'buyer')
        ->assertJsonPath('data.can_confirm_receipt', false);
});

it('404s another customer’s order, and a malformed one', function (): void {
    $fixture = inTransitFixture();

    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->getJson("/api/v1/orders/{$fixture['order']->uuid}/shipment")->assertNotFound();
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/shipment/confirm")->assertNotFound();

    $this->actingAs($fixture['customer'], 'customer');

    /*
     * THE UUID-CAST TRAP, NINTH WATCH. `shipments.order_uuid` is a native uuid
     * column on PostgreSQL, so a non-uuid segment would be SQLSTATE[22P02] — a
     * 500 on a page the customer opens — while SQLite quietly returns nothing.
     */
    foreach (['not-a-uuid', 'siparisim', (string) Str::uuid()] as $unknown) {
        $this->getJson("/api/v1/orders/{$unknown}/shipment")->assertNotFound();
        $this->postJson("/api/v1/orders/{$unknown}/shipment/confirm")->assertNotFound();
    }

    // And the parcel is untouched by any of it.
    expect($fixture['shipment']->fresh()->status)->toBe(ShipmentStatus::Shipped);
});

it('refuses a seller and a guest at the customer endpoints', function (): void {
    $fixture = inTransitFixture();

    // A guest never reaches the controller at all — the route is behind
    // `auth:sanctum`, so this is 401 before any of this module's code runs.
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/shipment/confirm")->assertUnauthorized();

    $this->actingAs(Seller::factory()->create(), 'seller');

    /*
     * A SELLER GETS 404, NOT 403, AND THE DIFFERENCE IS THE POINT. They ARE
     * authenticated — just not the person who bought this — and the ownership
     * check answers "not yours" and "does not exist" identically, so nobody can
     * discover which order uuids are real by watching the status code change.
     *
     * It also means a seller cannot reach delivery from this side either: the one
     * endpoint that sets a delivery date belongs to the buyer (ADR-064).
     */
    $this->postJson("/api/v1/orders/{$fixture['order']->uuid}/shipment/confirm")->assertNotFound();

    expect($fixture['shipment']->fresh()->delivered_at)->toBeNull();
});
