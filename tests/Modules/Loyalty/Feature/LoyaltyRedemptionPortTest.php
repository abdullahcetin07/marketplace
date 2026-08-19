<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Models\Customer;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyHold;
use App\Modules\Loyalty\Domain\Models\LoyaltyLedgerEntry;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Loyalty — the redemption command port (ADR-084)
|--------------------------------------------------------------------------
|
| hold → commit → release, the shape Inventory's reservation already uses, for
| the same reason: a shopper occupies something at the start of a payment that may
| never finish, and it has to come back on its own if it does not.
|
| Defaults in these tests: one point is worth 0,05 TL — so 100 points = 5,00 TL.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A customer with a balance.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function customerWithPoints(int $points): Customer
{
    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    LoyaltyLedgerEntry::factory()->create(['customer_uuid' => $customer->uuid, 'points' => $points]);

    return $customer;
}

it('quotes against the balance and the cart, and clamps to whichever binds', function (): void {
    $customer = customerWithPoints(500);

    // 500 points = 25,00 TL, cart is 100,00 → the BALANCE binds.
    $rich = app(LoyaltyContract::class)->quote($customer->uuid, 10_000);

    expect($rich['max_points'])->toBe(500)
        ->and($rich['discount_minor'])->toBe(2_500)
        ->and($rich['payable_minor'])->toBe(7_500);

    /*
     * **CHANGE IS NOT GIVEN IN CASH.** On a 5,00 TL basket only 100 of those 500
     * points can be spent — the rest would have to come back as money, which the
     * platform does not do.
     */
    $small = app(LoyaltyContract::class)->quote($customer->uuid, 500);

    expect($small['max_points'])->toBe(100)
        ->and($small['payable_minor'])->toBe(0);
});

it('lets points cover the whole cart, because there is no cap', function (): void {
    // Owner's decision, 2026-08-15: a customer may pay 100% with points; the
    // platform absorbs it and every seller is still paid in full.
    $customer = customerWithPoints(1_000);

    $quote = app(LoyaltyContract::class)->quote($customer->uuid, 5_000);

    expect($quote['points_applied'])->toBe(1_000)
        ->and($quote['discount_minor'])->toBe(5_000)
        ->and($quote['payable_minor'])->toBe(0);
});

it('holds without writing to the ledger, and releasing leaves no trace', function (): void {
    $customer = customerWithPoints(300);
    $group = (string) Str::uuid();

    $held = app(LoyaltyContract::class)->hold($customer->uuid, 200, $group);

    /*
     * **A HOLD IS A CLAIM ON WHAT MIGHT HAPPEN**, and the ledger records what did.
     * Writing holds into it would make the balance a sum of intentions.
     */
    expect($held)->toBe(200)
        ->and(LoyaltyHold::query()->count())->toBe(1)
        ->and(LoyaltyLedgerEntry::query()->count())->toBe(1);

    app(LoyaltyContract::class)->release($group);

    expect(LoyaltyHold::query()->count())->toBe(0)
        ->and(LoyaltyLedgerEntry::query()->count())->toBe(1);
});

it('re-holds the same group instead of stacking a second claim', function (): void {
    $customer = customerWithPoints(300);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 100, $group);
    $second = app(LoyaltyContract::class)->hold($customer->uuid, 250, $group);

    /*
     * A shopper who refreshes the payment page, or a client retrying a timed-out
     * request, must REPLACE their own claim — and be measured against a balance
     * that does not already count it.
     */
    expect($second)->toBe(250)
        ->and(LoyaltyHold::query()->count())->toBe(1)
        ->and(LoyaltyHold::query()->first()->points)->toBe(250);
});

it('stops two open tabs from spending the same points twice', function (): void {
    $customer = customerWithPoints(300);

    $first = app(LoyaltyContract::class)->hold($customer->uuid, 300, (string) Str::uuid());
    $second = app(LoyaltyContract::class)->hold($customer->uuid, 300, (string) Str::uuid());

    // The second basket sees the first one's claim: 300 spendable, 300 already
    // held, nothing left.
    expect($first)->toBe(300)
        ->and($second)->toBe(0);
});

it('commits exactly one negative row, however many times the callback arrives', function (): void {
    $customer = customerWithPoints(400);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 250, $group);

    $committed = app(LoyaltyContract::class)->commit($group);
    // PayTR retries its callback (ADR-060); the same success may arrive three times.
    $again = app(LoyaltyContract::class)->commit($group);

    $rows = LoyaltyLedgerEntry::query()->where('source_type', LoyaltyPointSource::Redemption->value)->get();

    expect($committed)->toBe(250)
        ->and($again)->toBe(0)
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->points)->toBe(-250)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(150);
});

it('re-credits everything a full refund undid', function (): void {
    $customer = customerWithPoints(400);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 250, $group);
    app(LoyaltyContract::class)->commit($group);

    $back = app(LoyaltyContract::class)->reverse($group, 'return', 1.0, (string) Str::uuid());

    /*
     * **THE CUSTOMER ENDS WHOLE**: points back, and the money goes back through
     * PayTR as it always did. A reversal is a NEW positive row — the ledger is
     * append-only, and "what did I spend on that order" stays answerable.
     */
    expect($back)->toBe(250)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(400);
});

it('re-credits the floor of a partial refund, and never more than was spent', function (): void {
    $customer = customerWithPoints(400);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 101, $group);
    app(LoyaltyContract::class)->commit($group);

    // Half of 101 is 50,5 — rounding up on every partial refund mints points out
    // of arithmetic.
    expect(app(LoyaltyContract::class)->reverse($group, 'cancellation', 0.5, (string) Str::uuid()))->toBe(50);

    // And a fraction that drifts over 1.0 through floating error hands back no
    // more than was committed.
    $group2 = (string) Str::uuid();
    app(LoyaltyContract::class)->hold($customer->uuid, 60, $group2);
    app(LoyaltyContract::class)->commit($group2);

    expect(app(LoyaltyContract::class)->reverse($group2, 'return', 1.0000001, (string) Str::uuid()))->toBe(60);
});

it('offers nothing at all when the programme is switched off', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);
    settings()->set('loyalty.enabled', false);

    $customer = customerWithPoints(500);
    $group = (string) Str::uuid();

    $quote = app(LoyaltyContract::class)->quote($customer->uuid, 10_000);

    expect($quote['max_points'])->toBe(0)
        ->and($quote['payable_minor'])->toBe(10_000)
        ->and(app(LoyaltyContract::class)->hold($customer->uuid, 100, $group))->toBe(0);
});

it('sums two partial refunds to exactly what was spent, never half and never one and a half', function (): void {
    /*
     * **THE AUDIT FINDING (2026-08-18), FROM BOTH SIDES.** Two partial RETURNS of
     * one order used to share the key `"{group}:return"`: the unique index dropped
     * the second silently and the customer got back half their points. Keying per
     * refund WITHOUT the delta is the mirror bug — the second refund's `fully`
     * branch wants the whole amount and would credit it on top of the first, 1.5×
     * at the platform's expense.
     *
     * A running total is the only shape that lands on exactly what was spent.
     */
    $customer = customerWithPoints(1_000);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 401, $group);
    app(LoyaltyContract::class)->commit($group);

    // Half the basket comes back, then the other half — two separate refunds.
    $first = app(LoyaltyContract::class)->reverse($group, 'return', 0.5, (string) Str::uuid());
    $second = app(LoyaltyContract::class)->reverse($group, 'return', 1.0, (string) Str::uuid());

    expect($first)->toBe(200)
        // 401 − 200 already back = 201, not another 401 and not nothing.
        ->and($second)->toBe(201)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(1_000);
});

it('lands on the same total however finely the refunds are sliced', function (): void {
    $customer = customerWithPoints(1_000);
    $group = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 300, $group);
    app(LoyaltyContract::class)->commit($group);

    // Thirds, with the rounding remainder landing wherever it falls.
    foreach ([1 / 3, 2 / 3, 1.0] as $cumulative) {
        app(LoyaltyContract::class)->reverse($group, 'return', $cumulative, (string) Str::uuid());
    }

    expect(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(1_000);
});

it('credits a retried refund event once', function (): void {
    $customer = customerWithPoints(500);
    $group = (string) Str::uuid();
    $refund = (string) Str::uuid();

    app(LoyaltyContract::class)->hold($customer->uuid, 200, $group);
    app(LoyaltyContract::class)->commit($group);

    // The same PaymentRefund delivered twice — the idempotency key is the refund.
    expect(app(LoyaltyContract::class)->reverse($group, 'return', 1.0, $refund))->toBe(200)
        ->and(app(LoyaltyContract::class)->reverse($group, 'return', 1.0, $refund))->toBe(0)
        ->and(app(LoyaltyLedgerRepositoryContract::class)->balanceFor($customer->uuid))->toBe(500);
});
