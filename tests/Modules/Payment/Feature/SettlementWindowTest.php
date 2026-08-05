<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Listeners\OpenSettlementWindows;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Payment\Domain\Support\SellerBalance;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Delivery starts two clocks (ADR-064, Shipping.md §4, S3)
|--------------------------------------------------------------------------
|
| **THIS IS WHAT SHIPPING WAS BUILT FOR.** ADR-060 left payout manual-only and P5
| left the customer refund admin-only, both because the platform had no notion of
| delivery. It has one now, and these are the two consequences the money side
| draws from it:
|
|   PAYOUT HOLD    a seller is not paid for goods the buyer can still send back,
|                  so their money is OWED but not PAYABLE until
|                  `delivered_at + payout_hold_days`.
|   RETURN WINDOW  the buyer may return until `delivered_at + return_days`
|                  — S4's guard.
|
| Both dates are FROZEN at delivery rather than derived on read: an operator
| shortening the hold must not make last month's deliveries suddenly payable, nor
| lengthening it withdraw a payout a seller was already promised.
|
| And `delivered_at` comes off the EVENT, never the consuming clock — a listener
| running an hour behind must not push a seller's payday an hour out.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * The delivery event, in the only shape a class-string subscriber can rely on.
 * Named for this file because Pest shares ONE global function namespace.
 */
function deliveryEvent(string $orderUuid, string $sellerOrgUuid, ?string $deliveredAt = null): object
{
    return (object) [
        'shipmentUuid' => (string) Str::uuid(),
        'orderUuid' => $orderUuid,
        'sellerOrgUuid' => $sellerOrgUuid,
        'deliveredAt' => $deliveredAt ?? now()->toIso8601String(),
        'deliveredVia' => 'buyer',
    ];
}

/**
 * A seller who sold one order for `$netMinor`, as the ledger would record it.
 *
 * @return array{seller: string, order: string}
 */
function soldOrder(int $saleMinor = 12_000, int $commissionMinor = 2_160): array
{
    $seller = (string) Str::uuid();
    $order = (string) Str::uuid();
    $payment = (string) Str::uuid();

    foreach ([[LedgerEntryType::SaleCredit, $saleMinor], [LedgerEntryType::CommissionDebit, $commissionMinor]] as [$type, $amount]) {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $seller,
            'type' => $type,
            'amount_minor' => $type->signedAmount($amount),
            'order_uuid' => $order,
            'payment_uuid' => $payment,
        ]);
    }

    return ['seller' => $seller, 'order' => $order];
}

function windowAdmin(): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    return $admin;
}

/*
|--------------------------------------------------------------------------
| The event opens the windows
|--------------------------------------------------------------------------
*/

it('opens both windows from the delivery date the event carried', function (): void {
    $sold = soldOrder();

    // An event that was raised two days ago and is only being consumed now — a
    // queue behind, a retry, a worker restarted.
    $deliveredAt = now()->subDays(2);

    app(OpenSettlementWindows::class)->handle(
        deliveryEvent($sold['order'], $sold['seller'], $deliveredAt->toIso8601String()),
    );

    $window = SettlementWindow::query()->where('order_uuid', $sold['order'])->firstOrFail();

    /*
     * MEASURED FROM THE EVENT'S DATE, NOT FROM `now()`. Had this listener read the
     * clock, the seller's payday would have moved two days out for no reason but
     * queue latency — and a payout date that depends on queue latency is one
     * nobody can reconcile.
     */
    expect($window->delivered_at->toDateString())->toBe($deliveredAt->toDateString())
        ->and($window->payout_eligible_at->toDateString())->toBe($deliveredAt->copy()->addDays(14)->toDateString())
        ->and($window->return_window_ends_at->toDateString())->toBe($deliveredAt->copy()->addDays(14)->toDateString())
        // The provenance travels too: a payout released on a buyer-confirmed
        // delivery and one released because a clock ran out are the same money and
        // a different amount of confidence.
        ->and($window->delivered_via)->toBe('buyer');
});

it('freezes the windows, so editing the setting cannot move them', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $sold = soldOrder();

    app(OpenSettlementWindows::class)->handle(deliveryEvent($sold['order'], $sold['seller']));

    $eligibleAt = SettlementWindow::query()->where('order_uuid', $sold['order'])->value('payout_eligible_at');

    // Operations decides two months is better. That governs the NEXT delivery.
    settings()->set('shipping.payout_hold_days', 60);

    /*
     * THE REASON THESE ARE COLUMNS AND NOT A COMPUTATION. Derived on read, this
     * edit would have withdrawn a payout the seller had already been promised —
     * and shortening it would have made last month's deliveries retroactively
     * payable. Same discipline as an order line's frozen price.
     */
    expect(SettlementWindow::query()->where('order_uuid', $sold['order'])->value('payout_eligible_at')->toDateString())
        ->toBe($eligibleAt->toDateString());
});

it('ignores a replayed delivery rather than pushing the dates out', function (): void {
    $sold = soldOrder();

    $event = deliveryEvent($sold['order'], $sold['seller'], now()->subDays(3)->toIso8601String());

    app(OpenSettlementWindows::class)->handle($event);

    $first = SettlementWindow::query()->where('order_uuid', $sold['order'])->value('payout_eligible_at');

    $this->travel(5)->days();

    // A queue retry, days later. Shipping's own guard already refuses a second
    // delivery; this is the second line of that defence.
    app(OpenSettlementWindows::class)->handle(deliveryEvent($sold['order'], $sold['seller']));

    expect(SettlementWindow::query()->where('order_uuid', $sold['order'])->count())->toBe(1)
        ->and(SettlementWindow::query()->where('order_uuid', $sold['order'])->value('payout_eligible_at')->toIso8601String())
        ->toBe($first->toIso8601String());
});

it('refuses to guess when the event carries no usable date', function (): void {
    $sold = soldOrder();

    $event = deliveryEvent($sold['order'], $sold['seller']);
    $event->deliveredAt = '';

    app(OpenSettlementWindows::class)->handle($event);

    /*
     * NO WINDOW RATHER THAN A GUESSED ONE. Falling back to `now()` would silently
     * produce a payout schedule nobody intended — and the whole point of carrying
     * the timestamp is that this listener does not get to decide it.
     */
    expect(SettlementWindow::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Owed is not the same as payable
|--------------------------------------------------------------------------
*/

it('holds a seller’s money back until the parcel has been delivered long enough', function (): void {
    $sold = soldOrder(12_000, 2_160);

    // The sale is settled and the money is genuinely theirs: 12 000 − 18%.
    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(9_840);

    /*
     * BUT NOTHING IS PAYABLE, because nothing has been delivered. An order with no
     * window at all is held — treating "no window" as payable would pay a seller
     * the moment the card cleared, which is exactly what ADR-064 exists to
     * prevent.
     */
    $balance = SellerBalance::for($sold['seller']);

    expect($balance->balanceMinor)->toBe(9_840)
        ->and($balance->onHoldMinor)->toBe(9_840)
        ->and($balance->payableMinor)->toBe(0);

    app(OpenSettlementWindows::class)->handle(deliveryEvent($sold['order'], $sold['seller']));

    // Delivered — and still not payable: the buyer has two weeks to send it back.
    $balance = SellerBalance::for($sold['seller']);

    expect($balance->payableMinor)->toBe(0)
        ->and($balance->onHoldMinor)->toBe(9_840);

    $this->travel(15)->days();

    // The hold has expired. What was always owed is now drawable.
    $balance = SellerBalance::for($sold['seller']);

    expect($balance->balanceMinor)->toBe(9_840)
        ->and($balance->onHoldMinor)->toBe(0)
        ->and($balance->payableMinor)->toBe(9_840);
});

it('never holds back an entry that names no order', function (): void {
    $seller = (string) Str::uuid();

    /*
     * A DELIBERATE RULE, NOT AN ACCIDENT OF THE QUERY. A ledger row naming no
     * order cannot be tied to a delivery — it is an adjustment, a correction, a
     * payout reversal — and holding it hostage to a parcel that does not exist
     * would freeze a seller's money forever with nothing that could release it.
     */
    SellerLedgerEntry::factory()->forSeller($seller)->of(LedgerEntryType::SaleCredit, 5_000)->create();

    $balance = SellerBalance::for($seller);

    expect($balance->balanceMinor)->toBe(5_000)
        ->and($balance->onHoldMinor)->toBe(0)
        ->and($balance->payableMinor)->toBe(5_000);
});

it('does not treat a refunded order as money to withhold', function (): void {
    $sold = soldOrder(12_000, 2_160);

    // Delivered long ago and then refunded: the sale credit reversed and the
    // commission given back, so the order nets NEGATIVE.
    SettlementWindow::factory()->payable()->forOrder($sold['order'], $sold['seller'])->create();

    foreach ([[LedgerEntryType::RefundDebit, 12_000], [LedgerEntryType::RefundCommissionCredit, 2_160]] as [$type, $amount]) {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $sold['seller'],
            'type' => $type,
            'amount_minor' => $type->signedAmount($amount),
            'order_uuid' => $sold['order'],
            'payment_uuid' => (string) Str::uuid(),
        ]);
    }

    /*
     * "HOLDING BACK" A NEGATIVE WOULD ADD to what the seller may draw — paying
     * them extra for a return. A debt is not withheld; it is simply owed.
     */
    $balance = SellerBalance::for($sold['seller']);

    expect($balance->balanceMinor)->toBe(0)
        ->and($balance->onHoldMinor)->toBe(0)
        ->and($balance->payableMinor)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The payout ceiling moved
|--------------------------------------------------------------------------
*/

it('refuses to pay out for a parcel that has not been delivered', function (): void {
    $sold = soldOrder();
    $admin = windowAdmin();

    /*
     * THE CEILING S3 CHANGED. Until Shipping existed, a payout could draw the
     * whole balance the moment the card cleared. A seller must not be paid for
     * goods the buyer can still send back — which is why payout waits on DELIVERY
     * rather than on payment.
     */
    expect(fn () => app(CreatePayoutAction::class)->run($sold['seller'], 9_840, $admin->getKey()))
        ->toThrow(PaymentException::class);

    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(9_840);
});

it('pays out once the hold has expired, and not a day before', function (): void {
    $sold = soldOrder();
    $admin = windowAdmin();

    app(OpenSettlementWindows::class)->handle(deliveryEvent($sold['order'], $sold['seller']));

    $this->travel(13)->days();

    // Thirteen days. The buyer still has one.
    expect(fn () => app(CreatePayoutAction::class)->run($sold['seller'], 9_840, $admin->getKey()))
        ->toThrow(PaymentException::class);

    $this->travel(2)->days();

    app(CreatePayoutAction::class)->run($sold['seller'], 9_840, $admin->getKey());

    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(0);
});

it('tells an admin what is payable and what is merely owed', function (): void {
    $this->seedRolesAndPermissions();

    // Two orders for one seller: one delivered a month ago, one still in transit.
    $old = soldOrder(10_000, 1_800);
    $seller = $old['seller'];

    $fresh = (string) Str::uuid();

    foreach ([[LedgerEntryType::SaleCredit, 20_000], [LedgerEntryType::CommissionDebit, 3_600]] as [$type, $amount]) {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $seller,
            'type' => $type,
            'amount_minor' => $type->signedAmount($amount),
            'order_uuid' => $fresh,
            'payment_uuid' => (string) Str::uuid(),
        ]);
    }

    SettlementWindow::factory()->payable()->forOrder($old['order'], $seller)->create();

    $admin = windowAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    $this->actingAs($admin, 'admin');

    /*
     * THREE NUMBERS, REPORTED TOGETHER. A screen that read the total from one
     * place and enforced the payable figure in another would eventually show an
     * admin an amount the payout then refused — the single most annoying way for
     * a finance feature to be wrong.
     */
    $this->getJson("/api/v1/admin/sellers/{$seller}/balance")
        ->assertOk()
        ->assertJsonPath('data.balance_minor', 24_600)
        ->assertJsonPath('data.payable_minor', 8_200)
        ->assertJsonPath('data.on_hold_minor', 16_400);
});

it('reports what is on hold when it refuses a payout', function (): void {
    $sold = soldOrder();
    $admin = windowAdmin();

    try {
        app(CreatePayoutAction::class)->run($sold['seller'], 9_840, $admin->getKey());
        $this->fail('An undelivered order must not be payable.');
    } catch (PaymentException $exception) {
        // An admin told "you have 9 840" and then refused 9 840 learns nothing;
        // one told "0 payable, 9 840 awaiting delivery" knows to wait.
        expect($exception->getContext()['balance_minor'])->toBe(0)
            ->and($exception->getContext()['on_hold_minor'])->toBe(9_840);
    }
});
