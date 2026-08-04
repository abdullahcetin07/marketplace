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
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| P1 — the collection core (ADR-060, Payment.md §3/§5)
|--------------------------------------------------------------------------
|
| **This file is where ADR-054/057's promise is checked.** Placement only HELD the
| stock; a verified success callback is what commits it, and the assertions below
| are against the REAL Inventory rather than a mock — a test that mocked the
| reservation port would prove Payment calls a method, not that a seller's stock
| actually moved, which is the entire claim.
|
| What is pinned:
|
|   HASH          a forged or mistyped callback changes NOTHING
|   IDEMPOTENT    PayTR retries; the same payload settles once
|   COMMIT        success turns every held reservation into a real decrement
|   RELEASE       failure puts the units straight back on sale
|   EXACT KURUŞ   a callback naming a different amount is not this payment
|   NEVER 500     an unknown or non-uuid group 404s (the trap, 5th watch)
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A placed, awaiting-payment checkout group with real stock held behind it.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{group: string, orders: array<int, Order>, org: Organization, variant: ProductVariant, customerId: int}
 */
function paidCheckoutFixture(int $priceMinor = 12_000, int $stock = 10, int $quantity = 2): array
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
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    $customerId = 1;

    $address = app(CreateCustomerAddressAction::class)->run($customerId, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    app(AddCartItemAction::class)->run($customerId, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $orders = app(CheckoutAction::class)->run($customerId, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    // Placement HOLDS the reservation (ADR-057) — it does not commit. That is the
    // state this whole file starts from.
    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    return [
        'group' => $orders[0]->checkout_group_uuid,
        'orders' => $orders,
        'org' => $organization,
        'variant' => $variant,
        'customerId' => $customerId,
    ];
}

/**
 * A Payment row for a group, as `initiate` would have left it — without touching
 * the PSP, which the gateway tests cover separately.
 */
function pendingPaymentFor(string $group, int $amountMinor, int $customerId = 1): Payment
{
    return Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
        'customer_uuid' => 'musteri',
        'amount_minor' => $amountMinor,
        'status' => PaymentStatus::Pending,
    ]);
}

/**
 * PayTR's callback payload, hashed exactly as the real one is.
 *
 * @return array<string, mixed>
 */
function paytrCallback(Payment $payment, string $status = 'success', ?int $amountMinor = null): array
{
    $amount = (string) ($amountMinor ?? $payment->amount_minor);

    return [
        'merchant_oid' => $payment->uuid,
        'status' => $status,
        'total_amount' => $amount,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').$status.$amount,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ];
}

function groupTotalMinor(string $group): int
{
    return (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');
}

/*
|--------------------------------------------------------------------------
| The hash — the only thing standing between this endpoint and a free order
|--------------------------------------------------------------------------
*/

it('refuses a callback whose hash does not verify, and changes nothing', function (): void {
    $fixture = paidCheckoutFixture();
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    /*
     * THE ATTACK THIS ENDPOINT INVITES. It is public and unauthenticated, so a
     * forged POST claiming "success" is the obvious move — and it would be a free
     * order plus a real stock decrement. The hash is the entire defence.
     */
    $forged = paytrCallback($payment);
    $forged['hash'] = base64_encode('nope');

    expect(app(SettlePaymentCallbackAction::class)->run($forged))->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($fixture['orders'][0]->fresh()->status)->toBe(OrderStatus::AwaitingPayment);

    // And the units are still HELD — not committed, not released.
    expect(StockReservation::query()->count())->toBeGreaterThan(0);
});

it('accepts a correctly hashed callback', function (): void {
    $fixture = paidCheckoutFixture();
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    expect(app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment)))->toBeTrue()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

/*
|--------------------------------------------------------------------------
| Success — where the stock finally leaves
|--------------------------------------------------------------------------
*/

it('COMMITS every held reservation on success — the ADR-057 promise', function (): void {
    $fixture = paidCheckoutFixture(stock: 10, quantity: 2);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    $inventory = app(InventoryQueryContract::class);
    $before = $inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid);

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment));

    /*
     * THE ASSERTION THE WHOLE MODULE EXISTS FOR. Placement held the units;
     * available had already dropped. What changes HERE is ON-HAND — the units
     * truly leave the seller's shelf, which is what a commit means and what
     * ADR-054 first promised, ADR-057 deferred, and Payment now delivers.
     */
    expect($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe($before - 2);

    // Committed, not released and not still held — the reservation row says which.
    expect(StockReservation::query()->first()->status->value)->toBe('committed');
});

it('flips every order in the group to paid, through Order’s own listener', function (): void {
    $fixture = paidCheckoutFixture();
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment));

    /*
     * PAYMENT DID NOT SET THIS. It announced `PaymentSucceeded`; Order subscribes
     * BY CLASS-STRING and moves its own status, because an order's state machine
     * is Order's. This assertion is what proves the class-string wiring is live —
     * the one part of the boundary that fails at runtime rather than at build
     * time.
     */
    foreach ($fixture['orders'] as $order) {
        expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    }
});

it('settles once, however many times PayTR retries', function (): void {
    $fixture = paidCheckoutFixture(stock: 10, quantity: 2);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    $inventory = app(InventoryQueryContract::class);
    $before = $inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid);
    $callback = paytrCallback($payment);

    /*
     * PayTR RETRIES UNTIL IT HEARS "OK" — for days. So the same payload arriving
     * five times is the NORMAL case, not an edge one, and without idempotency it
     * would commit already-committed stock and (from P3) credit a seller five
     * times over.
     */
    $outcomes = [];

    for ($i = 0; $i < 5; $i++) {
        $outcomes[] = app(SettlePaymentCallbackAction::class)->run($callback);
    }

    // Exactly one call did the work; the rest changed nothing and said so.
    expect($outcomes)->toBe([true, false, false, false, false])
        ->and($inventory->onHandFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe($before - 2)
        ->and(StockReservation::query()->count())->toBe(1);
});

it('refuses a verified callback that names the wrong amount', function (): void {
    $fixture = paidCheckoutFixture();
    $total = groupTotalMinor($fixture['group']);
    $payment = pendingPaymentFor($fixture['group'], $total);

    /*
     * CORRECTLY HASHED, WRONG TOTAL — which is either a misconfiguration or a
     * payload replayed from a different order. Accepting it would confirm orders
     * nobody paid for. Exact integer comparison, kuruş both sides, no tolerance:
     * PayTR's unit is the platform's (ADR-005).
     */
    $callback = paytrCallback($payment, amountMinor: $total - 1);

    expect(app(SettlePaymentCallbackAction::class)->run($callback))->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| Failure — the units go straight back
|--------------------------------------------------------------------------
*/

it('RELEASES the reservations when the payment fails', function (): void {
    $fixture = paidCheckoutFixture(stock: 10, quantity: 2);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    $inventory = app(InventoryQueryContract::class);
    $before = $inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid);

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment, status: 'failed'));

    /*
     * BACK ON SALE IMMEDIATELY. A declined card must not keep somebody else's
     * units off the shelf for thirty minutes waiting for the expiry sweep — the
     * next buyer is trying to buy them right now.
     */
    expect($inventory->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe($before + 2)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(StockReservation::query()->first()->status->value)->toBe('released');
});

it('leaves the orders payable after a failure rather than cancelling them', function (): void {
    $fixture = paidCheckoutFixture();
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment, status: 'failed'));

    /*
     * A DECLINED CARD IS NOT A CANCELLED ORDER. The shopper may fix it and try
     * again in thirty seconds, and `Cancelled` is terminal in both directions —
     * cancelling would throw away the basket irreversibly. The stock has already
     * gone back, so nothing is hoarded meanwhile, and the 30-minute expiry sweep
     * still catches what is genuinely abandoned.
     */
    foreach ($fixture['orders'] as $order) {
        expect($order->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
    }
});

/*
|--------------------------------------------------------------------------
| The endpoint itself
|--------------------------------------------------------------------------
*/

it('always answers plain "OK", whatever happened', function (): void {
    $fixture = paidCheckoutFixture();
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    $forged = paytrCallback($payment);
    $forged['hash'] = 'rubbish';

    /*
     * PayTR RETRIES ANYTHING THAT IS NOT EXACTLY "OK", for days. So a 500 on a
     * payload the platform will never accept becomes a retry storm, and a 422 on
     * a duplicate makes it re-send a payment already settled. Every outcome is OK;
     * what actually happened is in the audit trail, not the status code.
     */
    foreach ([paytrCallback($payment), $forged, ['garbage' => true]] as $payload) {
        $response = $this->post('/api/v1/payments/paytr/callback', $payload);

        expect($response->status())->toBe(200)
            ->and($response->getContent())->toBe('OK');
    }
});

it('needs no authentication, because PayTR has none to give', function (): void {
    // No session, no token. The hash is the security model — see the file header.
    $this->post('/api/v1/payments/paytr/callback', ['merchant_oid' => 'x'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| The uuid-cast trap — 5th watch
|--------------------------------------------------------------------------
*/

it('404s a non-uuid or unknown checkout group instead of 500ing', function (): void {
    $customer = App\Models\Customer::factory()->create();

    $this->actingAs($customer, 'customer');

    /*
     * THE FIFTH APPEARANCE WATCH. `checkout_group_uuid` is a native uuid column on
     * PostgreSQL, so `where('checkout_group_uuid', 'not-a-uuid')` is
     * SQLSTATE[22P02] — a 500, not a miss — while on SQLite it quietly returns
     * false and every test passes. ADR-049, the geo cascade, the listing filter
     * and the buy box all shipped that bug; this is the guard applied before
     * Payment joins them.
     */
    foreach (['not-a-uuid', 'sepet', (string) Str::uuid()] as $group) {
        $this->postJson("/api/v1/checkout/{$group}/pay")->assertNotFound();
    }
});

/*
|--------------------------------------------------------------------------
| The commission snapshot (ADR-061, Payment.md §6) — P2
|--------------------------------------------------------------------------
*/

it('freezes the commission onto every line when the payment succeeds', function (): void {
    $fixture = paidCheckoutFixture(priceMinor: 12_000, quantity: 2);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    // Before: the classification is frozen (checkout) but the money is not.
    $line = $fixture['orders'][0]->lines()->first();

    expect($line->commission_minor)->toBeNull()
        ->and($line->category_uuid)->not->toBeNull();
    expect($line->category_path_uuids)->toBeArray();
    expect($line->category_path_uuids)->not->toBeEmpty();

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment));

    /*
     * AT PAYMENT, NOT AT CHECKOUT, and the timing is the decision. A rate edited
     * between placing and paying SHOULD apply — no money had changed hands — and
     * one edited afterwards must not.
     *
     * 24 000 kuruş at the seeded platform default of 18% = 4 320.
     */
    $line = $line->fresh();

    expect($line->commission_rate)->toBe('0.1800')
        ->and($line->commission_minor)->toBe(4_320)
        ->and($line->commission_resolved_at)->not->toBeNull();
});

it('computes commission on the KDV-INCLUSIVE line total', function (): void {
    $fixture = paidCheckoutFixture(priceMinor: 12_990, quantity: 1);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));

    app(SettlePaymentCallbackAction::class)->run(paytrCallback($payment));

    $line = $fixture['orders'][0]->lines()->first()->fresh();

    /*
     * THE LINE CARRIES KDV INSIDE IT (ADR-042/055) and the commission is a share
     * of the GROSS the buyer paid, not of the net (owner choice, Payment.md §6).
     * 12 990 × 18% = 2 338,2 → 2 338. Computed from the full 12 990, never from
     * the ~10 825 that would be left after extracting the %20 KDV.
     */
    expect($line->line_total_minor)->toBe(12_990)
        ->and($line->line_tax_minor)->toBeGreaterThan(0)
        ->and($line->commission_minor)->toBe(2_338);
});

it('never moves a commission once it is settled', function (): void {
    $fixture = paidCheckoutFixture(priceMinor: 12_000, quantity: 2);
    $payment = pendingPaymentFor($fixture['group'], groupTotalMinor($fixture['group']));
    $callback = paytrCallback($payment);

    app(SettlePaymentCallbackAction::class)->run($callback);

    $line = $fixture['orders'][0]->lines()->first()->fresh();
    $settled = $line->commission_minor;

    /*
     * THE SENTENCE ADR-061 IS MADE OF: a commission a seller has already seen
     * deducted must never move. Three ways it could, all refused:
     */

    // 1. A retried callback — PayTR sends them for days.
    app(SettlePaymentCallbackAction::class)->run($callback);
    expect($line->fresh()->commission_minor)->toBe($settled);

    // 2. A rule change afterwards. It re-prices the NEXT sale, not this one.
    CommissionRule::query()->update(['rate' => '0.5000']);
    app(SettlePaymentCallbackAction::class)->run($callback);
    expect($line->fresh()->commission_minor)->toBe($settled);

    // 3. A direct write. `OrderLine` refuses it: the line is immutable, and the
    //    commission columns are the ONE deferred write, permitted exactly once.
    $line->update(['commission_minor' => 1]);
    expect($line->fresh()->commission_minor)->toBe($settled);
});

it('keeps every other column of a placed line frozen', function (): void {
    $fixture = paidCheckoutFixture();
    $line = $fixture['orders'][0]->lines()->first();
    $price = $line->unit_price_minor;

    /*
     * THE COMMISSION HOLE IS NARROW ON PURPOSE. A write that touches anything
     * besides the three commission fields is refused whatever else it does — so
     * the exception ADR-061 needed cannot become a general-purpose escape hatch
     * by adding one more key to the same `update()` call.
     */
    $line->update(['unit_price_minor' => 1]);
    expect($line->fresh()->unit_price_minor)->toBe($price);

    $line->update(['commission_minor' => 500, 'unit_price_minor' => 1]);
    expect($line->fresh()->unit_price_minor)->toBe($price)
        ->and($line->fresh()->commission_minor)->toBeNull();
});
