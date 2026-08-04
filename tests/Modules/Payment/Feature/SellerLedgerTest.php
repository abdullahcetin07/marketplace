<?php

declare(strict_types=1);

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
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The seller balance ledger (ADR-062, Payment.md §7)
|--------------------------------------------------------------------------
|
| What a seller is owed is a LEDGER, not a number. There is no balance column
| anywhere on this platform and that absence is the decision: a stored balance is
| a number that can drift from the events that produced it, and the first time it
| does, nobody can tell which is right.
|
|   TWO ENTRIES PER ORDER   the sale, and the platform's share of it
|   NET OF COMMISSION       balance = Σ, and the sum is the answer
|   PER SELLER              one card, N orders, N merchants, N balances
|   APPEND-ONLY             a correction to money is a new entry, never a rewrite
|   IDEMPOTENT              PayTR retries; the seller is credited once
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A checkout group containing one order per seller, placed and awaiting payment.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @param array<int, int> $prices one seller per entry, at that unit price
 *
 * @return array{group: string, orders: array<int, Order>, sellers: array<int, string>}
 */
function ledgerFixture(array $prices = [12_000], int $quantity = 1): array
{
    $customerId = 1;
    $sellers = [];

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
            quantity: $quantity,
        ));

        $sellers[] = $organization->uuid;
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

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    return ['group' => $orders[0]->checkout_group_uuid, 'orders' => $orders, 'sellers' => $sellers];
}

/**
 * Pay the group, exactly as a verified PayTR callback would.
 */
function settleGroup(string $group): Payment
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

    return $payment;
}

/*
|--------------------------------------------------------------------------
| The two entries, and what they sum to
|--------------------------------------------------------------------------
*/

it('credits the sale and debits the commission, as two separate rows', function (): void {
    $fixture = ledgerFixture([12_000]);
    settleGroup($fixture['group']);

    $seller = $fixture['sellers'][0];
    $entries = SellerLedgerEntry::query()->forSeller($seller)->orderBy('id')->get();

    /*
     * TWO ROWS, NOT ONE NET FIGURE. "You earned 120,00 and we took 21,60" is a
     * sentence a seller can check; "you earned 98,40" is one they can only
     * accept. The default rate is the seeded 18%.
     */
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->type)->toBe(LedgerEntryType::SaleCredit)
        ->and($entries[0]->amount_minor)->toBe(12_000)
        ->and($entries[1]->type)->toBe(LedgerEntryType::CommissionDebit)
        // STORED NEGATIVE, which is what makes the balance a plain SUM rather
        // than a CASE ladder that must know what every type means.
        ->and($entries[1]->amount_minor)->toBe(-2_160);
});

it('makes the balance the net of commission, computed on read', function (): void {
    $fixture = ledgerFixture([12_000]);
    settleGroup($fixture['group']);

    // 12 000 − 18% = 9 840. A SUM, not a column: there is no balance stored
    // anywhere on this platform, and that absence is the decision.
    expect(SellerLedgerEntry::balanceFor($fixture['sellers'][0]))->toBe(9_840);
});

it('agrees to the kuruş with the commission frozen on the order lines', function (): void {
    $fixture = ledgerFixture([12_990]);
    settleGroup($fixture['group']);

    $frozen = (int) $fixture['orders'][0]->lines()->sum('commission_minor');

    /*
     * THE PROPERTY THE WHOLE DESIGN PROTECTS. The ledger READS the frozen figure
     * rather than resolving the rules a second time — two computations of one
     * number is precisely how they stop agreeing, and a kuruş of drift per order
     * is unreconcilable a year later.
     */
    $debit = SellerLedgerEntry::query()
        ->forSeller($fixture['sellers'][0])
        ->ofType(LedgerEntryType::CommissionDebit)
        ->value('amount_minor');

    expect($frozen)->toBe(2_338)
        ->and((int) $debit)->toBe(-$frozen);
});

/*
|--------------------------------------------------------------------------
| One card, many sellers
|--------------------------------------------------------------------------
*/

it('splits one payment across every seller in the group', function (): void {
    // Three merchants in one basket — the ADR-052 split, seen from the money side.
    $fixture = ledgerFixture([10_000, 20_000, 5_000]);

    settleGroup($fixture['group']);

    [$a, $b, $c] = $fixture['sellers'];

    /*
     * EACH SELLER'S BALANCE IS THEIR OWN. Payment rejoined the basket to charge
     * it once; this splits it again to settle it, because each merchant earned a
     * different amount and owes a different commission.
     */
    expect(SellerLedgerEntry::balanceFor($a))->toBe(8_200)
        ->and(SellerLedgerEntry::balanceFor($b))->toBe(16_400)
        ->and(SellerLedgerEntry::balanceFor($c))->toBe(4_100);

    // Six rows in total — two per seller, and nobody's entries leaked into
    // anybody else's balance.
    expect(SellerLedgerEntry::query()->count())->toBe(6);
});

it('applies each seller’s own commission rule', function (): void {
    $fixture = ledgerFixture([10_000, 10_000]);

    [$a, $b] = $fixture['sellers'];

    // One merchant negotiated 5%; the other stays on the platform's 18%.
    CommissionRule::factory()->scoped(['seller_org_uuid' => $a], '0.0500')->create();

    settleGroup($fixture['group']);

    expect(SellerLedgerEntry::balanceFor($a))->toBe(9_500)
        ->and(SellerLedgerEntry::balanceFor($b))->toBe(8_200);
});

/*
|--------------------------------------------------------------------------
| Append-only
|--------------------------------------------------------------------------
*/

it('refuses to update or delete an entry, at all', function (): void {
    $entry = SellerLedgerEntry::factory()->of(LedgerEntryType::SaleCredit, 5_000)->create();

    /*
     * NO ESCAPE HATCH — not even the narrow, once-only one `OrderLine` has for its
     * commission. The difference: a line's commission is genuinely decided later,
     * while every field of a ledger row is known the moment it is written. So an
     * edit could only be a correction, and a correction to money is a NEW ENTRY.
     */
    $entry->update(['amount_minor' => 999_999]);
    expect($entry->fresh()->amount_minor)->toBe(5_000);

    $entry->delete();
    expect(SellerLedgerEntry::query()->whereKey($entry->getKey())->exists())->toBeTrue();

    // And the balance is unmoved by either attempt.
    expect(SellerLedgerEntry::balanceFor($entry->seller_org_uuid))->toBe(5_000);
});

it('points a debit downwards however the caller passes the amount', function (): void {
    $seller = 'seller-x';

    // The sign is decided by the TYPE, once, so no call site can append a positive
    // commission and pay the seller the platform's cut.
    SellerLedgerEntry::factory()->forSeller($seller)->of(LedgerEntryType::SaleCredit, 10_000)->create();
    SellerLedgerEntry::factory()->forSeller($seller)->of(LedgerEntryType::CommissionDebit, 1_800)->create();
    // A caller that passes a negative magnitude for a debit meant a debit.
    SellerLedgerEntry::factory()->forSeller($seller)->of(LedgerEntryType::PayoutDebit, -5_000)->create();

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(3_200);
});

/*
|--------------------------------------------------------------------------
| Retries
|--------------------------------------------------------------------------
*/

it('credits a seller once, however many times PayTR retries', function (): void {
    $fixture = ledgerFixture([12_000]);
    $total = (int) Order::query()->where('checkout_group_uuid', $fixture['group'])->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $fixture['group'],
        'customer_id' => 1,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    $callback = [
        'merchant_oid' => $payment->uuid,
        'status' => 'success',
        'total_amount' => (string) $total,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').'success'.$total,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ];

    /*
     * PayTR RE-SENDS UNTIL IT HEARS "OK". Without idempotency the seller would be
     * credited five times over — an accounting error nobody would notice until
     * payout, and one that looks exactly like a successful sale.
     */
    for ($i = 0; $i < 5; $i++) {
        app(SettlePaymentCallbackAction::class)->run($callback);
    }

    expect(SellerLedgerEntry::query()->count())->toBe(2)
        ->and(SellerLedgerEntry::balanceFor($fixture['sellers'][0]))->toBe(9_840);
});

it('leaves the ledger untouched when a payment fails', function (): void {
    $fixture = ledgerFixture([12_000]);
    $total = (int) Order::query()->where('checkout_group_uuid', $fixture['group'])->sum('grand_total_minor');

    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $fixture['group'],
        'customer_id' => 1,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    app(SettlePaymentCallbackAction::class)->run([
        'merchant_oid' => $payment->uuid,
        'status' => 'failed',
        'total_amount' => (string) $total,
        'hash' => base64_encode(hash_hmac(
            'sha256',
            $payment->uuid.config('payment.paytr.merchant_salt').'failed'.$total,
            (string) config('payment.paytr.merchant_key'),
            true,
        )),
    ]);

    // No money arrived, so nobody is owed anything. The ledger records what
    // happened, and nothing happened.
    expect(SellerLedgerEntry::query()->count())->toBe(0)
        ->and(SellerLedgerEntry::balanceFor($fixture['sellers'][0]))->toBe(0);
});

it('does not credit a seller when the commission was never frozen', function (): void {
    $fixture = ledgerFixture([12_000]);

    // Simulate the order having no frozen commission — the state a pre-ADR-061
    // line is in, and the one case the listener must not guess at.
    $fixture['orders'][0]->lines()->getQuery()->update(['commission_resolved_at' => now(), 'commission_minor' => null]);

    settleGroup($fixture['group']);

    /*
     * UNSETTLED IS NOT ZERO. Crediting the full sale with no commission taken
     * would silently overpay the merchant, and the platform would find out at
     * payout. A missing pair of entries is recoverable; a wrong one is an argument.
     */
    expect(SellerLedgerEntry::query()->count())->toBe(0);
});
