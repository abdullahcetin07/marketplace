<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\LoyaltyContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyHold;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
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
use App\Modules\Payment\Application\Actions\InitiatePaymentAction;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Points cover the whole cart — the path with no card (ADR-084)
|--------------------------------------------------------------------------
|
| There is no redemption cap (owner's decision, 2026-08-15), so a balance that
| covers the basket is reachable in normal use. PayTR rejects a zero-amount order,
| so this path skips the gateway entirely and settles directly — and it must do
| everything the callback would have: commit the stock, spend the points, mark the
| payment paid, announce it.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    // A REAL FAILURE IF ANYTHING CALLS OUT. The whole point of this path is that
    // the gateway is never touched, so a stray request must break the test rather
    // than reach the internet.
    Http::preventStrayRequests();
});

/**
 * A placed, unpaid checkout group for one customer.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{group: string, variant: ProductVariant, orgUuid: string, customerUuid: string, totalMinor: int}
 */
function pointsOnlyCheckout(int $priceMinor = 5_000): array
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
        stockQuantity: 10,
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
        quantity: 1,
    ));

    $orders = app(CheckoutAction::class)->run($customerId, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    /*
     * THE UUID THE PAYMENT PATH WILL USE, read through the same Core port the
     * action reads it through. Taking it off the order model instead would work
     * here and diverge the day either side changes whose uuid it carries.
     */
    $customer = app(App\Core\Domain\Contracts\OrderQueryContract::class)
        ->checkoutGroupCustomer($orders[0]->checkout_group_uuid);

    return [
        'group' => $orders[0]->checkout_group_uuid,
        'variant' => $variant,
        'orgUuid' => $organization->uuid,
        'customerUuid' => (string) $customer['uuid'],
        'totalMinor' => (int) $orders[0]->grand_total_minor,
    ];
}

it('pays a whole basket with points, without touching PayTR', function (): void {
    $checkout = pointsOnlyCheckout();

    // Enough points to cover the basket outright.
    LoyaltyLedgerEntry::factory()->create([
        'customer_uuid' => $checkout['customerUuid'],
        'points' => (int) ceil($checkout['totalMinor'] / 5) + 100,
    ]);

    $result = app(InitiatePaymentAction::class)->run(
        $checkout['group'],
        '127.0.0.1',
        999_999,
    );

    /** @var Payment $payment */
    $payment = $result['payment'];

    /*
     * **NO TOKEN, BECAUSE THERE IS NO iFRAME TO OPEN.** The storefront reads the
     * null as "already paid, go to the thank-you page".
     */
    expect($result['token'])->toBeNull()
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->amount_minor)->toBe(0)
        ->and($payment->discount_minor)->toBe($checkout['totalMinor'])
        // Named so a human reading the row knows why there is no PSP reference.
        ->and($payment->provider_reference)->toBe('points');
});

it('commits the stock and the points on that same path', function (): void {
    $checkout = pointsOnlyCheckout();

    LoyaltyLedgerEntry::factory()->create([
        'customer_uuid' => $checkout['customerUuid'],
        'points' => (int) ceil($checkout['totalMinor'] / 5) + 100,
    ]);

    $before = app(InventoryQueryContract::class)
        ->availableFor($checkout['variant']->uuid, $checkout['orgUuid']);

    app(InitiatePaymentAction::class)->run($checkout['group'], '127.0.0.1', 999_999);

    /*
     * **THE SAME END STATE AS A CARD PAYMENT**, which is the whole reason this path
     * repeats the callback's settle() rather than inventing a shorter one: the
     * reservation is committed, the order is paid, and the points left the ledger.
     */
    $spent = LoyaltyLedgerEntry::query()
        ->where('source_type', LoyaltyPointSource::Redemption->value)
        ->where('source_uuid', $checkout['group'])
        ->first();

    expect($spent)->not->toBeNull()
        ->and($spent->points)->toBeLessThan(0)
        // The hold is gone: it did its job.
        ->and(LoyaltyHold::query()->count())->toBe(0)
        ->and(Order::query()->where('checkout_group_uuid', $checkout['group'])->first()->status)
        ->toBe(OrderStatus::Paid)
        // Availability is unchanged BECAUSE the hold became a commitment rather
        // than being released — the units left the pool at placement.
        ->and(app(InventoryQueryContract::class)->availableFor($checkout['variant']->uuid, $checkout['orgUuid']))
        ->toBe($before);
});

it('still charges the card when the points only cover part of it', function (): void {
    $checkout = pointsOnlyCheckout();

    // 100 points = 5,00 TL against a 50,00 basket.
    LoyaltyLedgerEntry::factory()->create([
        'customer_uuid' => $checkout['customerUuid'],
        'points' => 100,
    ]);

    /*
     * The gateway IS called here, so the stray-request guard would fail the test
     * if PayTR were reached for real. Faked, because this test is about the
     * arithmetic rather than the PSP.
     */
    Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'tok_test'], 200)]);

    $result = app(InitiatePaymentAction::class)->run($checkout['group'], '127.0.0.1', 100);

    /** @var Payment $payment */
    $payment = $result['payment'];

    expect($payment->amount_minor)->toBe($checkout['totalMinor'] - 500)
        ->and($payment->discount_minor)->toBe(500)
        ->and($payment->points_spent)->toBe(100)
        // Pending: the hash-verified callback is still the truth (ADR-060).
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        // Held, not yet spent — no ledger row until the callback commits.
        ->and(LoyaltyHold::query()->where('checkout_group_uuid', $checkout['group'])->count())->toBe(1)
        ->and(LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Redemption->value)->count())->toBe(0);
});

it('releases a previous attempt’s hold when the customer unticks the control', function (): void {
    $checkout = pointsOnlyCheckout();

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $checkout['customerUuid'], 'points' => 100]);

    app(LoyaltyContract::class)->hold($checkout['customerUuid'], 100, $checkout['group']);

    Http::fake(['*' => Http::response(['status' => 'success', 'token' => 'tok_test'], 200)]);

    app(InitiatePaymentAction::class)->run($checkout['group'], '127.0.0.1', 0);

    /*
     * Paying with zero points must not leave the balance occupied by an earlier
     * attempt — otherwise a shopper who changes their mind cannot spend those
     * points anywhere until a sweep that does not exist releases them.
     */
    expect(LoyaltyHold::query()->count())->toBe(0)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($checkout['customerUuid']))->toBe(100);
});
