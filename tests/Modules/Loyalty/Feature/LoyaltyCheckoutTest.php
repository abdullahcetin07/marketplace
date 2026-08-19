<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Models\Customer;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Loyalty at checkout — the money edges (ADR-084)
|--------------------------------------------------------------------------
|
| The parts where points meet real money: what a refund gives back, and what a
| points-funded purchase earns. Both are places where getting the arithmetic
| slightly wrong mints or destroys value quietly.
|
| One point is 0,05 TL throughout.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A settled basket: one payment, its seller-orders, and a delivered parcel each.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @param array<int, int> $orderTotals grand totals in minor units
 *
 * @return array{customer: Customer, group: string, orders: array<int, Order>}
 */
function settledBasket(array $orderTotals, int $pointsSpent = 0, int $discountMinor = 0, int $daysAgo = 20): array
{
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $group = (string) Str::uuid();

    $orders = [];

    foreach ($orderTotals as $total) {
        /** @var Order $order */
        $order = Order::factory()->status(OrderStatus::Delivered)->create([
            'checkout_group_uuid' => $group,
            'customer_id' => $customer->getKey(),
            'customer_uuid' => $customer->uuid,
            'grand_total_minor' => $total,
        ]);

        Shipment::factory()->create([
            'order_uuid' => $order->uuid,
            'seller_org_uuid' => $order->selling_org_uuid,
            'delivered_at' => now()->subDays($daysAgo),
        ]);

        $orders[] = $order;
    }

    Payment::factory()->create([
        'checkout_group_uuid' => $group,
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'amount_minor' => array_sum($orderTotals) - $discountMinor,
        'points_spent' => $pointsSpent,
        'discount_minor' => $discountMinor,
    ]);

    return ['customer' => $customer, 'group' => $group, 'orders' => $orders];
}

it('earns on the cash paid, not on the money the points covered', function (): void {
    // 200,00 TL basket, 40,00 of it paid with 800 points → 160,00 in cash.
    $basket = settledBasket([20_000], pointsSpent: 800, discountMinor: 4_000);

    $eligible = app(OrderQueryContract::class)->pointsEligibleSellerOrders(now());

    /*
     * **POINTS MUST NOT REGENERATE ON MONEY PAID WITH POINTS** (ADR-082 §2.3).
     * Earning on the invoice total would hand back points for the discount itself,
     * compounding a little on every purchase.
     */
    expect($eligible)->toHaveCount(1)
        ->and($eligible[0]['paid_minor'])->toBe(16_000);
});

it('spreads the discount across a multi-seller basket in proportion', function (): void {
    // 30/70 basket, 50,00 TL of points across the whole thing.
    $basket = settledBasket([30_000, 70_000], pointsSpent: 1_000, discountMinor: 5_000);

    $eligible = collect(app(OrderQueryContract::class)->pointsEligibleSellerOrders(now()))
        ->keyBy('order_uuid');

    $small = $eligible[$basket['orders'][0]->uuid]['paid_minor'];
    $large = $eligible[$basket['orders'][1]->uuid]['paid_minor'];

    /*
     * The seller-orders still SETTLE on their full amounts — the platform funds
     * the discount — but the customer's cash split 30/70, so the earn base does.
     */
    expect($small)->toBe(30_000 - 1_500)
        ->and($large)->toBe(70_000 - 3_500);
});

it('leaves the earn base alone when no points were spent', function (): void {
    $basket = settledBasket([12_345]);

    $eligible = app(OrderQueryContract::class)->pointsEligibleSellerOrders(now());

    expect($eligible[0]['paid_minor'])->toBe(12_345);
});

it('gives back every point a full refund undid', function (): void {
    $basket = settledBasket([10_000], pointsSpent: 600, discountMinor: 3_000);

    LoyaltyLedgerEntry::factory()->create([
        'customer_uuid' => $basket['customer']->uuid,
        'points' => 1_000,
    ]);
    // The spend, as the callback would have written it.
    app(LoyaltyContract::class)->hold($basket['customer']->uuid, 600, $basket['group']);
    app(LoyaltyContract::class)->commit($basket['group']);

    expect(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($basket['customer']->uuid))->toBe(400);

    app(LoyaltyContract::class)->reverse($basket['group'], 'return', 1.0, (string) Str::uuid());

    /*
     * **THE CUSTOMER ENDS WHOLE**: the card goes back through PayTR as it always
     * did, and the points return as a NEW positive row — the spend is never erased,
     * so "what did I spend on that order" stays answerable.
     */
    expect(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($basket['customer']->uuid))->toBe(1_000)
        ->and(LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Reversal->value)->count())->toBe(1);
});

it('re-credits a partial refund in proportion to the whole basket, card plus points', function (): void {
    /*
     * **THE DENOMINATOR IS CARD + POINTS.** A 100,00 basket settled with 60,00 of
     * card and 40,00 of points: refunding 50,00 is half the BASKET, so half the
     * points come back. Dividing by the card charge alone would return five sixths
     * of them.
     */
    $basket = settledBasket([10_000], pointsSpent: 800, discountMinor: 4_000);

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $basket['customer']->uuid, 'points' => 1_000]);
    app(LoyaltyContract::class)->hold($basket['customer']->uuid, 800, $basket['group']);
    app(LoyaltyContract::class)->commit($basket['group']);

    // 50,00 TL of a 100,00 basket = 0.5.
    $back = app(LoyaltyContract::class)->reverse($basket['group'], 'cancellation', 0.5, (string) Str::uuid());

    expect($back)->toBe(400);
});
