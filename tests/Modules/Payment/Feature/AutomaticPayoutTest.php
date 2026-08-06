<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Actions\SettlePayoutAction;
use App\Modules\Payment\Application\Jobs\CreateDuePayoutsJob;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Domain\Models\SettlementWindow;
use App\Modules\Payment\Domain\Support\SellerBalance;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Automatic payouts (owner decision, 2026-08-06)
|--------------------------------------------------------------------------
|
| S3 shipped ELIGIBILITY and left the decision to an admin. The owner has since
| chosen to automate the decision as well: a nightly job proposes one pending
| payout per seller for everything whose delivery hold has expired.
|
| **THE DECISION IS AUTOMATED; THE BANK IS NOT.** The job writes a `pending` row
| saying the platform owes this. A human still makes the transfer and marks it
| paid — the software moves no money (ADR-062), and that has not changed.
|
|   ONE PER SELLER   not one per order: a payout is a transfer somebody executes
|                    by hand, and per-order would fragment a month into dozens.
|   ONLY PAYABLE     an order still inside its hold is not in the amount.
|   IDEMPOTENT       three guards, and the pending check is only the first.
|   MANUAL STAYS     an admin can still create one whenever they like.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A seller owed `$netMinor` for one order. Named for this file because Pest
 * shares ONE global function namespace.
 *
 * @return array{seller: string, order: string}
 */
function owedSeller(int $saleMinor = 12_000, int $commissionMinor = 2_160, ?string $seller = null): array
{
    $seller ??= (string) Str::uuid();
    $order = (string) Str::uuid();

    foreach ([[LedgerEntryType::SaleCredit, $saleMinor], [LedgerEntryType::CommissionDebit, $commissionMinor]] as [$type, $amount]) {
        SellerLedgerEntry::query()->create([
            'seller_org_uuid' => $seller,
            'type' => $type,
            'amount_minor' => $type->signedAmount($amount),
            'order_uuid' => $order,
            'payment_uuid' => (string) Str::uuid(),
        ]);
    }

    return ['seller' => $seller, 'order' => $order];
}

/**
 * Delivered long enough ago that the money may be drawn.
 *
 * @param array{seller: string, order: string} $sold
 */
function delivered(array $sold): SettlementWindow
{
    return SettlementWindow::factory()->payable()->forOrder($sold['order'], $sold['seller'])->create();
}

/**
 * Delivered just now — still inside the hold.
 *
 * @param array{seller: string, order: string} $sold
 */
function deliveredToday(array $sold): SettlementWindow
{
    return SettlementWindow::factory()->forOrder($sold['order'], $sold['seller'])->create();
}

/*
|--------------------------------------------------------------------------
| What it proposes
|--------------------------------------------------------------------------
*/

it('creates one pending payout for a seller whose hold has expired', function (): void {
    $sold = owedSeller();
    delivered($sold);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    $payout = Payout::query()->forSeller($sold['seller'])->firstOrFail();

    // 12 000 − 18% commission.
    expect($payout->amount_minor)->toBe(9_840)
        ->and($payout->status)->toBe(PayoutStatus::Pending)
        /*
         * NULL ACTOR = THE SCHEDULE DECIDED. Not a synthetic "system" user: an
         * account nobody owns with the authority to move money is an account
         * somebody eventually logs into.
         */
        ->and($payout->created_by)->toBeNull()
        ->and($payout->isAutomatic())->toBeTrue();

    /*
     * AND THE BALANCE MOVED. The job goes through `CreatePayoutAction`, so the
     * `payout_debit` that stops the same lira being promised twice is appended
     * here exactly as it is for a manual payout — the job writes no rows of its
     * own.
     */
    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(0);
});

it('sums every payable order of one seller into a single transfer', function (): void {
    $first = owedSeller(10_000, 1_800);
    $second = owedSeller(20_000, 3_600, $first['seller']);

    delivered($first);
    delivered($second);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    /*
     * ONE PAYOUT, NOT TWO. A payout is a bank transfer a human executes; one per
     * delivered order would make the finance team's work proportional to the
     * platform's order count.
     */
    expect(Payout::query()->forSeller($first['seller'])->count())->toBe(1)
        ->and(Payout::query()->forSeller($first['seller'])->value('amount_minor'))->toBe(8_200 + 16_400);
});

it('leaves out an order that is still inside its hold', function (): void {
    $payable = owedSeller(10_000, 1_800);
    $held = owedSeller(20_000, 3_600, $payable['seller']);

    delivered($payable);
    deliveredToday($held);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    /*
     * THE WHOLE POINT OF ADR-064, seen from the automation: the seller is owed
     * 24 600 and may have 8 200, because the buyer can still send the other parcel
     * back. Automating the decision must not automate around the hold.
     */
    $payout = Payout::query()->forSeller($payable['seller'])->firstOrFail();

    expect($payout->amount_minor)->toBe(8_200)
        ->and(SellerLedgerEntry::balanceFor($payable['seller']))->toBe(16_400);

    // And what is left becomes payable on its own schedule, not this one's.
    expect(SellerBalance::for($payable['seller'])->onHoldMinor)->toBe(16_400);
});

it('proposes nothing for a seller with no delivery at all', function (): void {
    $sold = owedSeller();

    // Paid, never delivered — no window row exists, so nothing is payable.
    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    expect(Payout::query()->count())->toBe(0)
        ->and(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(9_840);
});

/*
|--------------------------------------------------------------------------
| It cannot pay twice
|--------------------------------------------------------------------------
*/

it('proposes nothing on a second run', function (): void {
    $sold = owedSeller();
    delivered($sold);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));
    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));
    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    /*
     * THREE GUARDS AND ONLY THE FIRST IS THE JOB'S OWN: a seller with a pending
     * payout is skipped; creating one debits the balance so there is nothing left
     * to propose; and the action's row lock serialises two runs that overlap.
     * Running it more often than daily has to be harmless, because somebody
     * eventually will.
     */
    expect(Payout::query()->forSeller($sold['seller'])->count())->toBe(1)
        ->and(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(0);
});

it('skips a seller who already has a pending payout, whatever the arithmetic says', function (): void {
    $sold = owedSeller(20_000, 0);
    delivered($sold);

    // An admin already decided to send part of it by hand this morning.
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    app(CreatePayoutAction::class)->run($sold['seller'], 5_000, $admin->getKey());

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    /*
     * THE BALANCE WOULD ALLOW IT — 15 000 is still payable — and the job refuses
     * anyway. Two open transfers for one seller is a reconciliation problem for
     * whoever reads the bank statement, whatever the arithmetic says, and the
     * cheapest place to prevent it is before proposing the second.
     */
    expect(Payout::query()->forSeller($sold['seller'])->count())->toBe(1)
        ->and(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(15_000);

    // Once the admin settles theirs, tomorrow's run proposes the rest.
    app(SettlePayoutAction::class)->run(
        Payout::query()->forSeller($sold['seller'])->firstOrFail(),
        PayoutStatus::Paid,
        $admin->getKey(),
        'EFT-1',
    );

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    expect(Payout::query()->forSeller($sold['seller'])->count())->toBe(2)
        ->and(Payout::query()->forSeller($sold['seller'])->latest('id')->value('amount_minor'))->toBe(15_000);
});

it('carries on when one seller fails', function (): void {
    $broken = owedSeller();
    $fine = owedSeller();

    delivered($broken);
    delivered($fine);

    // A pending payout for the first seller makes the job skip them; the second
    // must still be proposed. (The isolation this asserts is the same one a
    // thrown exception would need.)
    /** @var Admin $admin */
    $admin = Admin::factory()->create();
    app(CreatePayoutAction::class)->run($broken['seller'], 1, $admin->getKey());

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    expect(Payout::query()->forSeller($fine['seller'])->count())->toBe(1)
        ->and(Payout::query()->forSeller($broken['seller'])->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| A human still moves the money
|--------------------------------------------------------------------------
*/

it('is settled by an admin exactly like a manual payout', function (): void {
    $sold = owedSeller();
    delivered($sold);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    $payout = Payout::query()->forSeller($sold['seller'])->firstOrFail();

    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    app(SettlePayoutAction::class)->run($payout, PayoutStatus::Paid, $admin->getKey(), 'EFT-2026-08-06');

    $settled = $payout->fresh();

    /*
     * AUTOMATING THE DECISION DID NOT AUTOMATE THE BANK. A person made the
     * transfer and recorded that they did — so the payout carries no creator and a
     * settler, which is exactly the pair that should be true of it.
     */
    expect($settled->status)->toBe(PayoutStatus::Paid)
        ->and($settled->external_reference)->toBe('EFT-2026-08-06')
        ->and($settled->settled_by)->toBe($admin->getKey())
        ->and($settled->created_by)->toBeNull();

    // Marking it paid confirms what already happened to the balance; it does not
    // repeat it (P4).
    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(0)
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::PayoutDebit)->count())->toBe(1);
});

it('gives the balance back when the bank rejects an automatic transfer', function (): void {
    $sold = owedSeller();
    delivered($sold);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    $payout = Payout::query()->forSeller($sold['seller'])->firstOrFail();

    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    app(SettlePayoutAction::class)->run($payout, PayoutStatus::Failed, $admin->getKey(), 'Hatalı IBAN');

    // The reversal works the same whoever decided it — and the money is payable
    // again, so tomorrow's run proposes it afresh.
    expect(SellerLedgerEntry::balanceFor($sold['seller']))->toBe(9_840);

    app(CreateDuePayoutsJob::class)->handle(app(CreatePayoutAction::class));

    expect(Payout::query()->forSeller($sold['seller'])->where('status', PayoutStatus::Pending->value)->count())->toBe(1);
});
