<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Inventory\Domain\Models\StockMovement;
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
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\PaymentRefund;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Refunds (Payment.md §8, P5)
|--------------------------------------------------------------------------
|
| **THE ONE OPERATION THAT MOVES REAL MONEY OUT.** A payout only records a
| transfer a human made; the callback only records what a buyer already did. Here
| a single POST makes PayTR send money back, debits a seller and puts units back
| on a shelf.
|
|   PSP FIRST        nothing is written until the provider has agreed
|   BOTH ENTRIES     the sale comes off AND the commission goes back
|   ORDERS, NOT LIRA "partial" means one seller's order, not part of the money
|   ONCE PER ORDER   a second click is a refusal, not a second debit
|   STOCK RETURNS    on-hand only — the hold ended when the sale did
|   MAY GO NEGATIVE  a refund after a payout blocks the NEXT payout, loses nothing
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
 * @return array{payment: Payment, orders: array<int, Order>, sellers: array<int, string>, variants: array<int, string>}
 */
function refundFixture(array $prices = [12_000]): array
{
    $customerId = 1;
    $sellers = [];
    $variants = [];

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

        $sellers[] = $organization->uuid;
        $variants[] = $variant->uuid;
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

    $total = (int) Order::query()->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    app(SettlePaymentCallbackAction::class)->run([
        'merchant_oid' => $payment->uuid,
        'status' => 'success',
        'total_amount' => (string) $total,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').'success'.$total,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ]);

    return [
        'payment' => $payment->fresh(),
        'orders' => Order::query()->where('checkout_group_uuid', $group)->orderBy('id')->get()->all(),
        'sellers' => $sellers,
        'variants' => $variants,
    ];
}

/**
 * PayTR agreeing to send the money back.
 */
function gatewayAgrees(): void
{
    Http::fake(['*' => Http::response(['status' => 'success', 'islem_id' => 'IADE-1'])]);
}

/**
 * PayTR refusing.
 */
function gatewayRefuses(): void
{
    Http::fake(['*' => Http::response(['status' => 'failed', 'err_msg' => 'iade süresi doldu'])]);
}

function refundAdmin(): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    return $admin;
}

/*
|--------------------------------------------------------------------------
| The ledger reverses
|--------------------------------------------------------------------------
*/

it('takes the sale back off the seller and gives the commission back', function (): void {
    $fixture = refundFixture([12_000]);
    $seller = $fixture['sellers'][0];

    // 12 000 sale − 18% commission = 9 840 owed before anything comes back.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(9_840);

    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(
        paymentUuid: $fixture['payment']->uuid,
        reason: 'Müşteri iade etti',
    ));

    /*
     * BOTH ENTRIES, MIRRORING THE TWO THE SALE MADE. Anything less leaves
     * somebody holding money the sale no longer justifies — and if the platform
     * kept its 18%, the SELLER would be paying for the buyer's return.
     */
    $entries = SellerLedgerEntry::query()->forSeller($seller)->orderBy('id')->get();

    expect($entries)->toHaveCount(4)
        ->and($entries[2]->type)->toBe(LedgerEntryType::RefundDebit)
        ->and($entries[2]->amount_minor)->toBe(-12_000)
        ->and($entries[3]->type)->toBe(LedgerEntryType::RefundCommissionCredit)
        ->and($entries[3]->amount_minor)->toBe(2_160);

    // Back to nothing owed — and by arithmetic on four rows, not by editing two.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(0);
});

it('keeps every fact on the trail rather than deleting the sale', function (): void {
    $fixture = refundFixture([12_000]);
    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid));

    /*
     * THE APPEND-ONLY PROPERTY, checked from the outside. A refund could have
     * been implemented by deleting the sale credit; then nothing would record
     * that a sale happened at all, and a seller asking "what did I sell in
     * August" would get an answer that changes retroactively.
     */
    expect(SellerLedgerEntry::query()->ofType(LedgerEntryType::SaleCredit)->count())->toBe(1)
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::CommissionDebit)->count())->toBe(1);

    // And the refund row itself refuses to be edited or deleted.
    $refund = PaymentRefund::query()->firstOrFail();
    $refund->update(['amount_minor' => 1]);
    $refund->delete();

    expect(PaymentRefund::query()->count())->toBe(1)
        ->and(PaymentRefund::query()->value('amount_minor'))->toBe(12_000);
});

/*
|--------------------------------------------------------------------------
| The stock comes back
|--------------------------------------------------------------------------
*/

it('puts the units back on the shelf, on-hand only', function (): void {
    $fixture = refundFixture([12_000]);

    $item = StockItem::query()
        ->where('selling_org_uuid', $fixture['sellers'][0])
        ->where('variant_uuid', $fixture['variants'][0])
        ->firstOrFail();

    // The sale took one unit and the hold ended with it.
    expect($item->on_hand)->toBe(19)
        ->and($item->reserved)->toBe(0);

    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid));

    $item->refresh();

    /*
     * ON-HAND ONLY, AND THAT ASYMMETRY IS THE POINT. `commit` lowered both
     * numbers; the reserved half was the hold ENDING, and a refund does not
     * re-hold anything — the unit is simply sellable again. Restoring `reserved`
     * too would hold stock for an order that has been refunded.
     */
    expect($item->on_hand)->toBe(20)
        ->and($item->reserved)->toBe(0);

    /*
     * ITS OWN MOVEMENT TYPE, which is what Order.md §12.5 said this primitive
     * would need. "Why did my stock go up?" has two possible answers — a hold was
     * abandoned, or a sale was undone — and only the type distinguishes them.
     */
    $movement = StockMovement::query()->where('type', StockMovementType::Restocked)->firstOrFail();

    expect($movement->on_hand_delta)->toBe(1)
        ->and($movement->reserved_delta)->toBe(0);
});

it('refuses to restock the same reservation twice', function (): void {
    $fixture = refundFixture([12_000]);
    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid));

    $reservation = App\Modules\Inventory\Domain\Models\StockReservation::query()->firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Restocked);

    /*
     * THE GUARANTEE THAT MATTERS MOST IN THIS MODULE. A retried refund that
     * restocked twice would invent stock that does not physically exist — and
     * unlike a lost movement, the seller would then sell it to somebody.
     */
    app(App\Core\Domain\Contracts\InventoryReservationContract::class)
        ->restock($reservation->reference);

    expect(StockItem::query()->value('on_hand'))->toBe(20)
        ->and(StockMovement::query()->where('type', StockMovementType::Restocked)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Partial means one seller's order
|--------------------------------------------------------------------------
*/

it('refunds one seller out of three and leaves the others alone', function (): void {
    $fixture = refundFixture([10_000, 20_000, 5_000]);
    [$a, $b, $c] = $fixture['sellers'];

    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(
        paymentUuid: $fixture['payment']->uuid,
        orderUuids: [$fixture['orders'][1]->uuid],
    ));

    /*
     * "PARTIALLY REFUNDED" MEANS SOME OF THE SELLERS' ORDERS, not some of the
     * money. It is the ADR-052 split seen from the refund side: the basket was one
     * card and three merchants, and only one parcel came back.
     */
    expect($fixture['payment']->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

    expect(SellerLedgerEntry::balanceFor($a))->toBe(8_200)
        ->and(SellerLedgerEntry::balanceFor($b))->toBe(0)
        ->and(SellerLedgerEntry::balanceFor($c))->toBe(4_100);

    // Only the refunded seller's order moved.
    expect($fixture['orders'][0]->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($fixture['orders'][1]->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($fixture['orders'][2]->fresh()->status)->toBe(OrderStatus::Paid);
});

it('becomes fully refunded once the last order comes back', function (): void {
    $fixture = refundFixture([10_000, 20_000]);
    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(
        paymentUuid: $fixture['payment']->uuid,
        orderUuids: [$fixture['orders'][0]->uuid],
    ));

    expect($fixture['payment']->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

    // A second refund, for the rest. The whole amount is now back, so the payment
    // is `refunded` — decided by Σ of the refund rows against the amount charged,
    // never by a stored running total.
    app(RefundPaymentAction::class)->run(new RefundRequestDTO(
        paymentUuid: $fixture['payment']->uuid,
        orderUuids: [$fixture['orders'][1]->uuid],
    ));

    expect($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and(PaymentRefund::query()->count())->toBe(2);
});

it('refuses a second refund of the same order', function (): void {
    $fixture = refundFixture([12_000]);
    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid));

    /*
     * THE SECOND-CLICK ANSWER. A refund is the one operation in this module a
     * human triggers by clicking, so it WILL be clicked twice — and unlike the
     * callback there is no PSP retry semantics to lean on.
     */
    expect(fn () => app(RefundPaymentAction::class)->run(
        new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid),
    ))->toThrow(PaymentException::class);

    expect(PaymentRefund::query()->count())->toBe(1)
        ->and(SellerLedgerEntry::balanceFor($fixture['sellers'][0]))->toBe(0);
});

it('refuses to refund a payment that never collected', function (): void {
    $group = (string) Str::uuid();

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'amount_minor' => 5_000,
        'status' => PaymentStatus::Failed,
    ]);

    gatewayAgrees();

    // Asking the PSP to reverse a charge it never took would be asking it about a
    // transaction it does not have.
    expect(fn () => app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $payment->uuid)))
        ->toThrow(PaymentException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The PSP has the last word
|--------------------------------------------------------------------------
*/

it('writes nothing at all when the provider refuses', function (): void {
    $fixture = refundFixture([12_000]);
    gatewayRefuses();

    expect(fn () => app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid)))
        ->toThrow(PaymentException::class);

    /*
     * THE PSP GOES FIRST, AND THIS IS WHY. Writing the ledger and calling the
     * gateway afterwards would leave a seller debited for a refund that never
     * happened — and unlike a payment, no callback is coming later to correct it.
     */
    expect(PaymentRefund::query()->count())->toBe(0)
        ->and(SellerLedgerEntry::balanceFor($fixture['sellers'][0]))->toBe(9_840)
        ->and($fixture['payment']->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(StockItem::query()->value('on_hand'))->toBe(19)
        ->and($fixture['orders'][0]->fresh()->status)->toBe(OrderStatus::Paid);
});

/*
|--------------------------------------------------------------------------
| Refund after payout
|--------------------------------------------------------------------------
*/

it('drives a paid-out balance negative and blocks the next payout until it is whole', function (): void {
    $fixture = refundFixture([12_000]);
    $seller = $fixture['sellers'][0];
    $admin = refundAdmin();

    // The platform pays the seller what it owed — before the buyer sends the
    // parcel back.
    app(CreatePayoutAction::class)->run($seller, 9_840, $admin->getKey());

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(0);

    gatewayAgrees();

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $fixture['payment']->uuid));

    /*
     * NEGATIVE, AND THAT IS ALLOWED (Payment.md §8). The money left the platform
     * twice — once to the seller, once back to the buyer — and the ledger says so
     * rather than clamping at zero and losing track of it. A mutable balance
     * column would have had to choose between lying and losing the money.
     */
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(-9_840);

    // And the ceiling that keeps a balance honest does the rest: nothing more may
    // be paid out until later sales make it whole.
    expect(fn () => app(CreatePayoutAction::class)->run($seller, 1, $admin->getKey()))
        ->toThrow(PaymentException::class);
});

/*
|--------------------------------------------------------------------------
| The admin API
|--------------------------------------------------------------------------
*/

it('refunds over the admin API and lists what went back', function (): void {
    $this->seedRolesAndPermissions();

    $fixture = refundFixture([12_000]);
    $admin = refundAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    $this->actingAs($admin, 'admin');
    gatewayAgrees();

    $response = $this->postJson("/api/v1/admin/payments/{$fixture['payment']->uuid}/refund", [
        'reason' => 'Ürün hasarlı geldi',
    ])->assertOk();

    // Money renders as a decimal STRING (005 §28), never a JSON number.
    $response->assertJsonPath('data.payment.status', 'refunded')
        ->assertJsonPath('data.refunds.0.amount', '120.00')
        ->assertJsonPath('data.refunds.0.reference', 'IADE-1');

    $this->getJson("/api/v1/admin/payments/{$fixture['payment']->uuid}/refunds")
        ->assertOk()
        ->assertJsonPath('data.0.reason', 'Ürün hasarlı geldi');
});

it('refuses the refund endpoint to a customer, and 404s a non-uuid payment', function (): void {
    $this->seedRolesAndPermissions();

    $customer = App\Models\Customer::factory()->create();
    $this->actingAs($customer, 'customer');

    /*
     * ADMIN-ONLY IN V1, and that is a stated narrowing of Payment.md §8 rather
     * than an oversight: whether a customer may reverse their own purchase
     * depends on whether it has SHIPPED, and there is no fulfilment state on this
     * platform yet. @see `PaymentPolicy::refund()`.
     */
    $this->postJson('/api/v1/admin/payments/'.Str::uuid().'/refund')->assertForbidden();

    $admin = refundAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));
    $this->actingAs($admin, 'admin');

    /*
     * THE UUID-CAST TRAP, SEVENTH WATCH. `payments.uuid` is a native uuid column
     * on PostgreSQL, so a non-uuid segment would be SQLSTATE[22P02] — a 500
     * rather than a miss.
     */
    $this->postJson('/api/v1/admin/payments/not-a-uuid/refund')->assertNotFound();
    $this->getJson('/api/v1/admin/payments/not-a-uuid/refunds')->assertNotFound();
});
