<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\GatewayRefundResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewayResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewaySessionDTO;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\DTOs\RefundRequestDTO;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Models\Payment;

/*
|--------------------------------------------------------------------------
| Refunding a basket that was paid entirely with points (ADR-084)
|--------------------------------------------------------------------------
|
| **THIS PATH 500'd IN PRODUCTION.** PayTR answered *"merchant_oid ile basarili
| odeme bulunamadi"* — not because the amount was zero, but because it had never
| seen the order at all: the payment never went through the PSP. A points-only
| basket has no card leg to reverse.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A gateway that records every call and would fail the test if used.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return object{refundCalls: int}
 */
function countingGateway(): object
{
    $spy = new class implements PaymentGatewayContract
    {
        public int $refundCalls = 0;

        public function initiate(PaymentIntentDTO $intent): GatewaySessionDTO
        {
            return new GatewaySessionDTO(token: 'tok_unused');
        }

        public function verifyCallback(array $raw): GatewayResultDTO
        {
            throw new RuntimeException('not used');
        }

        public function refund(PaymentRefundDTO $refund): GatewayRefundResultDTO
        {
            $this->refundCalls++;

            /*
             * WHAT PayTR ACTUALLY SAID. Returning the real refusal rather than a
             * success means a regression does not merely count a call — it
             * reproduces the 500.
             */
            return new GatewayRefundResultDTO(
                successful: false,
                failureReason: 'merchant_oid ile basarili odeme bulunamadi',
            );
        }
    };

    app()->instance(PaymentGatewayContract::class, $spy);

    return $spy;
}

/**
 * A real settled basket, then rewritten into the points-only shape.
 *
 * **THE ORDERS AND SETTLEMENTS HAVE TO BE REAL** — the refund builds its targets
 * from them — so the basket is placed and settled the ordinary way and only the
 * PAYMENT is then made to look like one paid entirely with points. That is exactly
 * the row production had: `amount_minor = 0`, `provider_reference = 'points'`, and
 * a PSP that has never heard of it.
 *
 * @return array{payment: Payment, group: string, customerUuid: string}
 */
function pointsOnlyRefundFixture(int $pointsSpent = 600): array
{
    $organization = App\Modules\Organization\Domain\Models\Organization::factory()->create();
    $store = App\Modules\Store\Domain\Models\Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => App\Modules\Store\Domain\Enums\StoreStatus::Active,
    ]);

    $category = App\Modules\Catalog\Domain\Models\Category::factory()
        ->childOf(App\Modules\Catalog\Domain\Models\Category::factory()->create())->create();
    $product = App\Modules\Catalog\Domain\Models\Product::factory()->for($category, 'category')->published()->create([
        'tax_rate_id' => App\Modules\Catalog\Domain\Models\TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = App\Modules\Catalog\Domain\Models\ProductVariant::factory()->for($product)->create();

    $offer = app(App\Modules\Offer\Application\Actions\CreateOfferAction::class)
        ->run(new App\Modules\Offer\Domain\DTOs\CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: 3_000,
            stockQuantity: 5,
        ));

    $customerId = 1;

    $address = app(App\Modules\Order\Application\Actions\CreateCustomerAddressAction::class)
        ->run($customerId, 'musteri', new App\Modules\Order\Domain\DTOs\CustomerAddressDTO(
            label: 'Ev',
            recipientName: 'Ayşe Yılmaz',
            phone: '+905551234567',
            line1: 'Bağdat Caddesi 120',
            city: 'İstanbul',
            countryCode: 'TR',
        ));

    app(App\Modules\Order\Application\Actions\AddCartItemAction::class)
        ->run($customerId, 'musteri', new App\Modules\Order\Domain\DTOs\AddCartItemDTO(offerUuid: $offer->uuid, quantity: 1));

    $orders = app(App\Modules\Order\Application\Actions\CheckoutAction::class)
        ->run($customerId, 'musteri', new App\Modules\Order\Domain\DTOs\CheckoutDTO(
            shippingAddressUuid: $address->uuid,
            billingAddressUuid: $address->uuid,
        ));

    $group = $orders[0]->checkout_group_uuid;

    app(App\Modules\Order\Application\Actions\PlaceOrderAction::class)->run($group);

    $total = (int) App\Modules\Order\Domain\Models\Order::query()
        ->where('checkout_group_uuid', $group)->sum('grand_total_minor');

    /** @var Payment $payment */
    $payment = Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customerId,
        'customer_uuid' => 'musteri',
        'amount_minor' => $total,
        'status' => PaymentStatus::Pending,
    ]);

    // Settled the ordinary way, so the settlements the refund reads exist.
    app(App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction::class)->run([
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

    // NOW the points-only shape: no card money, the basket covered by points.
    $payment->forceFill([
        'amount_minor' => 0,
        'points_spent' => $pointsSpent,
        'discount_minor' => $total,
        'provider_reference' => 'points',
    ])->save();

    return ['payment' => $payment->fresh(), 'group' => $group, 'customerUuid' => 'musteri'];
}

it('refunds a points-only order without calling the gateway at all', function (): void {
    /*
     * THE FIXTURE FIRST, THE SPY SECOND. Settling the basket goes through the real
     * gateway's `verifyCallback`; installing the spy before that would fail the
     * test on the setup rather than on the behaviour under test.
     */
    $fixture = pointsOnlyRefundFixture();
    $payment = $fixture['payment'];

    $gateway = countingGateway();
    $customerUuid = $fixture['customerUuid'];

    // The customer earned 1 000 and spent 600 of them on this basket.
    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $customerUuid, 'points' => 1_000]);
    app(LoyaltyContract::class)->hold($customerUuid, 600, $fixture['group']);
    app(LoyaltyContract::class)->commit($fixture['group']);

    app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $payment->uuid));

    /*
     * **ZERO GATEWAY CALLS.** Not "a call that was tolerated" — PayTR has never
     * heard of this order, and asking it anything can only fail.
     */
    expect((int) $gateway->refundCalls)->toBe(0)
        // The points came back: a new positive row, never an erased spend.
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customerUuid))->toBe(1_000)
        ->and(LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Reversal->value)->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
});

it('still calls the gateway when there was real card money', function (): void {
    $fixture = pointsOnlyRefundFixture();

    $gateway = countingGateway();

    /** @var Payment $payment */
    $payment = $fixture['payment'];

    // Rewritten back into a basket with real card money in it.
    $payment->forceFill(['amount_minor' => 2_500, 'discount_minor' => 500, 'points_spent' => 100])->save();

    /*
     * A PARTLY POINTS-FUNDED BASKET STILL HAS A CARD LEG. The guard is the card
     * AMOUNT, not the presence of points — skipping the gateway here would leave
     * the customer's money at the PSP.
     */
    try {
        app(RefundPaymentAction::class)->run(new RefundRequestDTO(paymentUuid: $payment->uuid));
    } catch (Throwable) {
        // The spy refuses, which is the point: it was asked.
    }

    expect((int) $gateway->refundCalls)->toBe(1);
});
