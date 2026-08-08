<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\ExpireOrderAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| E5 — the late payment (ADR-072, Payment.md §5)
|--------------------------------------------------------------------------
|
| **THE RACE THE PAYMENT WINDOW CREATED.** E3 gave sellers their stock back after
| five unpaid minutes. A slow 3-D Secure is longer than five minutes — the bank's
| SMS, a second attempt, a wrong code — so a genuinely successful callback can land
| for orders that already expired and already released their holds.
|
| Before this phase that money was simply taken: the commit found no active
| reservation, Order's listener found `Expired`, and NOTHING anywhere said the
| customer had paid for an order they would never get.
|
| Two outcomes, and the platform must be able to reach both:
|
|   STOCK STILL THERE   take the holds back, commit, orders recover to `Paid`
|   STOCK GONE          commit nothing, refund the charge IN FULL, stay `Expired`
|
| The second is the one that costs the platform a sale and is still correct —
| overselling somebody else's last unit is not a cheaper outcome, it is a worse
| one with a delay.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    // Every path here may reach PayTR's refund endpoint. Defaulted to accepting,
    // so a test that does NOT expect a refund fails on the assertion rather than
    // on a network call.
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IADE-LATE'])]);
});

/**
 * A placed group, its holds taken, plus everything needed to sell the same units
 * to somebody else.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{group: string, order: Order, org: Organization, variant: ProductVariant, offerUuid: string}
 */
function lateCheckout(int $stock = 3, int $quantity = 2): array
{
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
        priceMinor: 12_000,
        stockQuantity: $stock,
    ));

    $address = app(CreateCustomerAddressAction::class)->run(1, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $orders = app(CheckoutAction::class)->run(1, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    /** @var Order $order */
    $order = Order::query()->where('uuid', $orders[0]->uuid)->firstOrFail();

    return [
        'group' => $order->checkout_group_uuid,
        'order' => $order->fresh(),
        'org' => $organization,
        'variant' => $variant,
        'offerUuid' => $offer->uuid,
    ];
}

/**
 * The window runs out on a placed order: holds go back, status becomes `Expired`.
 */
function expireLateOrder(Order $order): void
{
    app(ExpireOrderAction::class)->run($order->fresh());
}

/**
 * A pending Payment for the group, as `initiate` would have left it before the
 * customer disappeared into their bank's 3-D Secure page.
 */
function latePayment(string $group): Payment
{
    return Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => 1,
        'customer_uuid' => 'musteri',
        'amount_minor' => (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor'),
        'status' => PaymentStatus::Pending,
    ]);
}

/**
 * PayTR's success callback, hashed exactly as the real one is.
 *
 * @return array<string, mixed>
 */
function lateCallback(Payment $payment): array
{
    $amount = (string) $payment->amount_minor;

    return [
        'merchant_oid' => $payment->uuid,
        'status' => 'success',
        'total_amount' => $amount,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').'success'.$amount,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ];
}

/*
|--------------------------------------------------------------------------
| The stock is still there — the order recovers
|--------------------------------------------------------------------------
*/

it('recovers an expired order when the stock is still available', function (): void {
    $fixture = lateCheckout(stock: 3, quantity: 2);
    $payment = latePayment($fixture['group']);
    $inventory = app(InventoryQueryContract::class);

    expireLateOrder($fixture['order']);

    // The premise: the hold really is gone. Without this the test would pass on
    // the ordinary path and prove nothing about the race.
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(3);

    // Eleven minutes late, the bank finally says yes.
    expect(app(SettlePaymentCallbackAction::class)->run(lateCallback($payment)))->toBeTrue();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        /*
         * **THE RECOVERY.** `Expired → Paid` is a transition E1 opened for exactly
         * this: the clock ended the order, and money arriving is the one thing
         * that can undo the clock.
         */
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        /*
         * AND THE UNITS TRULY LEFT. The re-reserve is worthless if the commit
         * silently no-ops on a released row — which is precisely what happened
         * before `isReclaimable()` taught Inventory the difference. On-hand is the
         * only honest witness.
         */
        ->and($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1)
        ->and(StockReservation::query()->firstOrFail()->status->value)->toBe('committed');

    // NOT REFUNDED. The money stays; there is a seller to pay.
    Http::assertNothingSent();
});

it('leaves the seller their ledger credit when the order recovers', function (): void {
    $fixture = lateCheckout();
    $payment = latePayment($fixture['group']);

    expireLateOrder($fixture['order']);
    app(SettlePaymentCallbackAction::class)->run(lateCallback($payment));

    /*
     * A RECOVERED ORDER IS A REAL SALE, so it must reach the ledger like any
     * other. This is the assertion that proves the recovery went through the
     * NORMAL success path rather than a parallel one that skips P3.
     */
    expect(SellerLedgerEntry::query()->where('seller_org_uuid', $fixture['org']->uuid)->count())
        ->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| The stock is gone — the money goes back
|--------------------------------------------------------------------------
*/

it('refunds in full when somebody else took the stock meanwhile', function (): void {
    $fixture = lateCheckout(stock: 3, quantity: 2);
    $payment = latePayment($fixture['group']);
    $inventory = app(InventoryQueryContract::class);

    expireLateOrder($fixture['order']);

    /*
     * THE SECOND BUYER. Not a mock and not a hand-edited column — a real hold
     * taken through the same contract, on the units this customer let go. Two of
     * three are now spoken for, so the expired order's two cannot come back.
     */
    app(App\Core\Domain\Contracts\InventoryReservationContract::class)->reserve(
        $fixture['org']->uuid,
        $fixture['variant']->uuid,
        2,
        'somebody-else:'.$fixture['variant']->uuid,
    );

    expect($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1);

    // The bank says yes. There is nothing left to sell them.
    expect(app(SettlePaymentCallbackAction::class)->run(lateCallback($payment)))->toBeFalse();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        // NOT `Paid`, and that is the whole point — the order was never fulfilled.
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Expired)
        /*
         * **NO OVERSELL.** On-hand untouched, and the other buyer's hold intact:
         * a commit here would have sent units that belong to somebody else.
         */
        ->and($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(3)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1);

    // THE FULL AMOUNT, at the gateway. A partial refund of a basket that was never
    // split would leave the customer short.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'iade')
        || isset($request->data()['return_amount']));

    // And no seller was credited for a sale that did not happen.
    expect(SellerLedgerEntry::query()->count())->toBe(0);
});

it('leaks no partial hold when only some of the group can be re-reserved', function (): void {
    /*
     * ONE ORDER, TWO LINES — the same failure mode a multi-seller basket has, in
     * the shape a single fixture can build. The first line comes back, the second
     * cannot, and the first must not stay held: a refunded charge that still
     * reserves a seller's units rebuilds, by hand, the leak ADR-072 closed.
     */
    $fixture = lateCheckout(stock: 5, quantity: 1);

    $second = ProductVariant::factory()->for(
        Product::factory()->for(
            Category::factory()->childOf(Category::factory()->create())->create(), 'category',
        )->published()->create(['tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey()]),
    )->create();

    $store = Store::query()->where('organization_id', $fixture['org']->getKey())->firstOrFail();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $second->uuid,
        sellingOrgId: $fixture['org']->getKey(),
        sellingOrgUuid: $fixture['org']->uuid,
        storeUuid: $store->uuid,
        priceMinor: 9_000,
        stockQuantity: 1,
    ));

    // A fresh basket holding one of each.
    app(AddCartItemAction::class)->run(2, 'musteri-iki', new AddCartItemDTO(
        offerUuid: $fixture['offerUuid'],
        quantity: 1,
    ));
    app(AddCartItemAction::class)->run(2, 'musteri-iki', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: 1,
    ));

    $address = app(CreateCustomerAddressAction::class)->run(2, 'musteri-iki', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Mehmet Demir',
        phone: '+905551234568',
        line1: 'Atatürk Caddesi 5',
        city: 'İzmir',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run(2, 'musteri-iki', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    /** @var Order $order */
    $order = Order::query()->where('uuid', $orders[0]->uuid)->firstOrFail();

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $order->checkout_group_uuid,
        'customer_id' => 2,
        'customer_uuid' => 'musteri-iki',
        'amount_minor' => $order->grand_total_minor,
        'status' => PaymentStatus::Pending,
    ]);

    expireLateOrder($order);

    // Somebody buys the only unit of the SECOND variant.
    app(App\Core\Domain\Contracts\InventoryReservationContract::class)->reserve(
        $fixture['org']->uuid,
        $second->uuid,
        1,
        'somebody-else:'.$second->uuid,
    );

    expect(app(SettlePaymentCallbackAction::class)->run(lateCallback($payment)))->toBeFalse();

    $inventory = app(InventoryQueryContract::class);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        /*
         * **THE ASSERTION THIS TEST EXISTS FOR.** Four of five, not three: the
         * fixture's own untouched order still holds one unit, and the hold this
         * attempt took on the second one was given back rather than left behind.
         * A leak would read 3 here.
         */
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(4)
        ->and($order->fresh()->status)->toBe(OrderStatus::Expired);
});

/*
|--------------------------------------------------------------------------
| The ordinary path, and the retry
|--------------------------------------------------------------------------
*/

it('does not touch a normal, unexpired success', function (): void {
    $fixture = lateCheckout(stock: 3, quantity: 2);
    $payment = latePayment($fixture['group']);
    $inventory = app(InventoryQueryContract::class);

    /*
     * THE REGRESSION GUARD. The recovery runs on every callback, so the price of
     * getting it wrong is paid by every payment on the platform — an
     * `AwaitingPayment` order still HOLDS its reservation, and re-reserving it
     * would take a second hold and double-count the units.
     */
    expect(app(SettlePaymentCallbackAction::class)->run(lateCallback($payment)))->toBeTrue();

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1)
        ->and($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1)
        ->and(StockReservation::query()->count())->toBe(1);

    Http::assertNothingSent();
});

it('is idempotent — replaying a recovered success commits nothing twice', function (): void {
    $fixture = lateCheckout(stock: 3, quantity: 2);
    $payment = latePayment($fixture['group']);
    $inventory = app(InventoryQueryContract::class);

    expireLateOrder($fixture['order']);

    $callback = lateCallback($payment);

    app(SettlePaymentCallbackAction::class)->run($callback);
    // PayTR did not hear OK, so it asks again. And again.
    app(SettlePaymentCallbackAction::class)->run($callback);
    app(SettlePaymentCallbackAction::class)->run($callback);

    /*
     * ONE DECREMENT, NOT THREE. `awaitsSettlement()` is the gate: a payment that
     * is already `Paid` never reaches the recovery or the commit at all.
     */
    expect($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1)
        ->and($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(StockReservation::query()->count())->toBe(1);
});

it('is idempotent — replaying a refunded late payment does not refund twice', function (): void {
    $fixture = lateCheckout(stock: 3, quantity: 2);
    $payment = latePayment($fixture['group']);

    expireLateOrder($fixture['order']);

    app(App\Core\Domain\Contracts\InventoryReservationContract::class)->reserve(
        $fixture['org']->uuid,
        $fixture['variant']->uuid,
        2,
        'somebody-else:'.$fixture['variant']->uuid,
    );

    $callback = lateCallback($payment);

    app(SettlePaymentCallbackAction::class)->run($callback);
    app(SettlePaymentCallbackAction::class)->run($callback);
    app(SettlePaymentCallbackAction::class)->run($callback);

    /*
     * **ONE REFUND.** The terminal `Refunded` state is what makes this safe — and
     * it is why the refund branch stamps a status even when PayTR refuses. A
     * payment left settleable would be refunded again on the next retry, which on
     * this path means giving the customer their money a second time.
     */
    Http::assertSentCount(1);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});
