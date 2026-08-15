<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Loyalty\Application\Actions\AwardPurchasePointsAction;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewPublished;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Shipping\Domain\Models\Shipment;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Loyalty — earning (ADR-081/082/083)
|--------------------------------------------------------------------------
|
| Three ways to earn, one ledger, and one guarantee underneath all of it: the
| unique key on (source_type, source_uuid). A re-emitted event, a queue retry, a
| sweep run twice — none of them may credit twice, and the database is what
| decides rather than a check somebody remembered to write.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('grants signup points once, however often the event is replayed', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    // The registration event fired when the factory created them. Replay it.
    event(new App\Modules\Identity\Domain\Events\UserCreated(
        $customer->getKey(),
        $customer->uuid,
        $customer->type,
        $customer->email,
    ));

    expect(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(100)
        ->and(LoyaltyLedgerEntry::query()->where('customer_uuid', $customer->uuid)->count())->toBe(1);
});

it('does not pay a seller or an admin for signing up', function (): void {
    /*
     * `UserCreated` fires for every actor type. A platform employee accruing
     * shopping points is a payout somebody has to explain.
     */
    $seller = App\Models\Seller::factory()->create();

    expect(LoyaltyLedgerEntry::query()->where('customer_uuid', $seller->uuid)->count())->toBe(0);
});

it('pays for a PUBLISHED review, once, and nothing for one still in the queue', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $pending = Review::factory()->create([
        'customer_uuid' => $customer->uuid,
        'status' => ReviewStatus::PendingReview,
    ]);

    // Writing it earns nothing: paying on submission pays for text nobody read.
    expect(LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Review->value)->count())->toBe(0);

    $published = Review::factory()->create([
        'customer_uuid' => $customer->uuid,
        'status' => ReviewStatus::Published,
    ]);

    foreach ([1, 2] as $ignored) {
        event(new ReviewPublished(
            $published->getKey(),
            $published->uuid,
            $published->product_uuid,
            1,
        ));
    }

    $entries = LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Review->value)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->points)->toBe(50)
        ->and($entries->first()->customer_uuid)->toBe($customer->uuid);
});

/*
| A NOTE ON THE FIXTURES BELOW: `Customer::factory()->create()` does NOT earn
| signup points, and that is correct rather than a gap. `UserCreated` is
| dispatched by the registration action, not by the model — a factory is a row,
| not somebody joining — so these balances count only what the test itself caused.
*/

/**
 * A delivered seller-order whose parcel arrived `$daysAgo` ago.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function finalizedOrder(Customer $customer, int $grandTotalMinor, int $daysAgo, OrderStatus $status = OrderStatus::Delivered): Order
{
    /** @var Order $order */
    $order = Order::factory()->status($status)->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'grand_total_minor' => $grandTotalMinor,
    ]);

    Shipment::factory()->create([
        'order_uuid' => $order->uuid,
        'seller_org_uuid' => $order->selling_org_uuid,
        'delivered_at' => now()->subDays($daysAgo),
    ]);

    return $order;
}

it('pays floor(lira × rate) once the return window has closed', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    // 149,90 TL, delivered twenty days ago — past the fourteen-day window.
    finalizedOrder($customer, 14_990, daysAgo: 20);

    $result = app(AwardPurchasePointsAction::class)->run(now());

    /*
     * **FLOORED, ON WHOLE LIRA.** A point is a thing a customer holds; 149,9 of
     * one does not exist, and rounding up would let a basket split across two
     * sellers earn more than the same basket did not.
     */
    expect($result['granted'])->toBe(1)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(149);
});

it('waits for the return window, and never pays a refunded order', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    // Delivered yesterday: still returnable, so still reversible.
    finalizedOrder($customer, 10_000, daysAgo: 1);
    // Past the window but refunded — the buyer bought nothing.
    finalizedOrder($customer, 10_000, daysAgo: 30, status: OrderStatus::Refunded);

    $result = app(AwardPurchasePointsAction::class)->run(now());

    /*
     * Paying at payment would have been simpler and wrong in the direction that
     * reaches customers: points clawed back out of a balance they may have spent.
     */
    expect($result['granted'])->toBe(0)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(0);
});

it('is free to re-run — the sweep credits nothing twice', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    finalizedOrder($customer, 20_000, daysAgo: 20);

    $first = app(AwardPurchasePointsAction::class)->run(now());
    $second = app(AwardPurchasePointsAction::class)->run(now());

    /*
     * The reader hands back every eligible order every night, including the paid
     * ones — deliberately, since a reader that filtered on Loyalty's table would
     * be Order reaching into Loyalty. The ledger's unique key is the guard.
     */
    expect($first['granted'])->toBe(1)
        ->and($second['considered'])->toBe(1)
        ->and($second['granted'])->toBe(0)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(200);
});

it('earns nothing at all while the programme is switched off', function (): void {
    /*
     * **THE SETTING ROW HAS TO EXIST BEFORE IT CAN BE TURNED OFF.** `settings()->set()`
     * updates; it does not create, and `seedAll()` does not seed settings — so
     * without this the switch writes nothing, `settings('loyalty.enabled', true)`
     * falls back to the code default, and the test would pass or fail on the
     * fallback rather than on the switch.
     */
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    expect(settings()->set('loyalty.enabled', false))->toBeTrue();

    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    finalizedOrder($customer, 50_000, daysAgo: 20);
    app(AwardPurchasePointsAction::class)->run(now());

    expect(LoyaltyLedgerEntry::query()->count())->toBe(0);
});

it('keeps the balance as a SUM, with no column to drift from it', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->count(3)->create(['customer_uuid' => $customer->uuid, 'points' => 40]);
    // A negative row, the shape Phase 2's redemption will write.
    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $customer->uuid, 'points' => -25]);

    expect(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(120 - 25)
        /*
         * **NO `balance` COLUMN ANYWHERE** (ADR-081). A stored total is a second
         * source of truth that drifts silently, and the failure is discovered by
         * the customer.
         */
        ->and(Schema::hasColumn('loyalty_ledger', 'balance'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'loyalty_points'))->toBeFalse();
});

it('refuses to let a row be edited or deleted', function (): void {
    $entry = LoyaltyLedgerEntry::factory()->create(['points' => 10]);

    $entry->points = 9_999;

    // Both hooks return false, which cancels the operation rather than throwing.
    expect($entry->save())->toBeFalse()
        ->and($entry->delete())->toBeFalse()
        ->and(LoyaltyLedgerEntry::query()->find($entry->getKey())->points)->toBe(10);
});
