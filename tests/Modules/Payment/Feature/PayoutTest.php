<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Actions\SettlePayoutAction;
use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Payouts (ADR-062, Payment.md §8)
|--------------------------------------------------------------------------
|
| **THE SOFTWARE MOVES NO MONEY.** A payout row says an admin decided to send a
| seller their balance; marking it paid says a human or a bank actually did. There
| is no banking integration behind any of this, and v1 does not want one.
|
|   CEILING       a payout can never exceed what the platform owes
|   DEBIT AT CREATE  the balance moves when the DECISION is made, not at `paid`
|   SERIALISED    two payouts for one seller cannot both pass the check
|   REVERSIBLE    a rejected transfer gives the balance back
|   FROZEN        the money fields never change; only the outcome is written
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A seller with a balance. Named for this file because Pest shares ONE global
 * function namespace.
 */
function sellerWithBalance(int $balanceMinor = 10_000): string
{
    $seller = (string) Str::uuid();

    SellerLedgerEntry::factory()
        ->forSeller($seller)
        ->of(LedgerEntryType::SaleCredit, $balanceMinor)
        ->create();

    return $seller;
}

function payoutAdmin(): Admin
{
    /** @var Admin $admin */
    $admin = Admin::factory()->create();

    return $admin;
}

/*
|--------------------------------------------------------------------------
| The ceiling
|--------------------------------------------------------------------------
*/

it('refuses to pay out more than the platform owes', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();

    /*
     * THE GUARD THAT KEEPS A BALANCE HONEST. A refund may drive a balance negative
     * afterwards (Payment.md §8) and that is allowed; the platform paying out
     * money it does not owe is not.
     */
    expect(fn () => app(CreatePayoutAction::class)->run($seller, 10_001, $admin->getKey()))
        ->toThrow(PaymentException::class);

    // Nothing was written — not the payout, not a debit.
    expect(Payout::query()->count())->toBe(0)
        ->and(SellerLedgerEntry::balanceFor($seller))->toBe(10_000);
});

it('allows paying out exactly the balance', function (): void {
    $seller = sellerWithBalance(10_000);

    app(CreatePayoutAction::class)->run($seller, 10_000, payoutAdmin()->getKey());

    // To the kuruş, and the seller is left at zero rather than blocked at 9 999.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(0);
});

it('refuses a zero or negative payout before it locks anything', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();

    // A non-positive payout would append a CREDIT — paying the seller by doing
    // nothing.
    foreach ([0, -500] as $amount) {
        expect(fn () => app(CreatePayoutAction::class)->run($seller, $amount, $admin->getKey()))
            ->toThrow(PaymentException::class);
    }

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(10_000);
});

/*
|--------------------------------------------------------------------------
| The debit, and when it happens
|--------------------------------------------------------------------------
*/

it('debits the balance when the payout is CREATED, not when it is paid', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();

    $payout = app(CreatePayoutAction::class)->run($seller, 4_000, $admin->getKey());

    /*
     * THE NON-OBVIOUS CHOICE, and the one the whole guard rests on. If the balance
     * only moved at `paid`, two admins could each create a payout for the whole
     * balance, both would pass their own check, and the seller would be overdrawn
     * when both transfers went through. Committing the balance when the DECISION
     * is made closes that window.
     */
    expect($payout->status)->toBe(PayoutStatus::Pending)
        ->and(SellerLedgerEntry::balanceFor($seller))->toBe(6_000);

    app(SettlePayoutAction::class)->run($payout, PayoutStatus::Paid, $admin->getKey(), 'EFT-99');

    // Marking it paid confirms what already happened to the balance; it does not
    // repeat it.
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(6_000)
        ->and($payout->fresh()->external_reference)->toBe('EFT-99');
});

it('stops a second payout from overdrawing what the first already claimed', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();

    app(CreatePayoutAction::class)->run($seller, 7_000, $admin->getKey());

    /*
     * THE SERIALISATION, OBSERVED THROUGH THE BALANCE rather than through timing.
     * The first payout's debit is committed before the second reads, because both
     * take a row lock on the same seller's ledger — so the second sees 3 000 and
     * cannot claim 7 000 again.
     */
    expect(fn () => app(CreatePayoutAction::class)->run($seller, 7_000, $admin->getKey()))
        ->toThrow(PaymentException::class);

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(3_000)
        ->and(Payout::query()->count())->toBe(1);

    // What is left is still payable.
    app(CreatePayoutAction::class)->run($seller, 3_000, $admin->getKey());

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| When the bank says no
|--------------------------------------------------------------------------
*/

it('gives the balance back when a transfer is rejected', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();

    $payout = app(CreatePayoutAction::class)->run($seller, 4_000, $admin->getKey());

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(6_000);

    app(SettlePayoutAction::class)->run($payout, PayoutStatus::Failed, $admin->getKey(), 'Hatalı IBAN');

    /*
     * THE MONEY NEVER LEFT, so the debit is reversed with a
     * `payout_reversal_credit` — the sixth ledger type, added for this and
     * reported against ADR-062. The ledger is append-only, so the debit cannot be
     * deleted; a compensating credit is the only honest way to say "that did not
     * happen", and it leaves both facts on the trail.
     */
    expect(SellerLedgerEntry::balanceFor($seller))->toBe(10_000)
        ->and($payout->fresh()->status)->toBe(PayoutStatus::Failed)
        ->and($payout->fresh()->failure_reason)->toBe('Hatalı IBAN');

    // Both facts survive: the debit AND its reversal.
    expect(SellerLedgerEntry::query()->ofType(LedgerEntryType::PayoutDebit)->count())->toBe(1)
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::PayoutReversalCredit)->count())->toBe(1);
});

it('records an outcome once, and refuses to re-record it', function (): void {
    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();
    $payout = app(CreatePayoutAction::class)->run($seller, 4_000, $admin->getKey());

    app(SettlePayoutAction::class)->run($payout, PayoutStatus::Failed, $admin->getKey(), 'Hatalı IBAN');

    /*
     * A rejected transfer is retried by creating a NEW payout, never by re-marking
     * this one — which keeps the failed attempt on the record for whoever
     * reconciles the bank statement. And it stops a double reversal crediting the
     * seller twice.
     */
    expect(fn () => app(SettlePayoutAction::class)->run($payout->fresh(), PayoutStatus::Paid, $admin->getKey()))
        ->toThrow(PaymentException::class);

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(10_000)
        ->and(SellerLedgerEntry::query()->ofType(LedgerEntryType::PayoutReversalCredit)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What a payout row will and will not accept
|--------------------------------------------------------------------------
*/

it('freezes the money fields and permits only the outcome', function (): void {
    $seller = sellerWithBalance(10_000);
    $payout = app(CreatePayoutAction::class)->run($seller, 4_000, payoutAdmin()->getKey());

    /*
     * THE LEDGER ALREADY DEBITED THIS AMOUNT. A row that could be edited afterwards
     * would make the balance a fiction — so the amount, the seller and the
     * currency are immutable, and the guard is narrow in both directions: slipping
     * `amount_minor` into the same call as a legitimate settlement fails the WHOLE
     * write.
     */
    $payout->update(['amount_minor' => 999_999]);
    expect($payout->fresh()->amount_minor)->toBe(4_000);

    $payout->update(['status' => PayoutStatus::Paid, 'amount_minor' => 1]);
    expect($payout->fresh()->amount_minor)->toBe(4_000)
        ->and($payout->fresh()->status)->toBe(PayoutStatus::Pending);

    // A payout is never deleted — a mistaken one is marked failed.
    $payout->delete();
    expect(Payout::query()->whereKey($payout->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The admin API
|--------------------------------------------------------------------------
*/

it('creates and settles a payout over the admin API', function (): void {
    $this->seedRolesAndPermissions();

    $seller = sellerWithBalance(10_000);
    $admin = payoutAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));

    $this->actingAs($admin, 'admin');

    $created = $this->postJson('/api/v1/admin/payouts', [
        'seller_id' => $seller,
        // KURUŞ on the wire, an integer — a decimal here would be the one place a
        // float could enter the payout chain (ADR-005).
        'amount_minor' => 4_000,
        'note' => 'Temmuz',
    ])->assertCreated()->json('data');

    // Money renders as a decimal STRING (005 §28), never a JSON number.
    expect($created['amount'])->toBe('40.00')
        ->and($created['status'])->toBe('pending')
        ->and(SellerLedgerEntry::balanceFor($seller))->toBe(6_000);

    $this->postJson("/api/v1/admin/payouts/{$created['id']}/settle", [
        'outcome' => 'paid',
        'detail' => 'EFT-123',
    ])->assertOk()->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.reference', 'EFT-123');

    // The balance page an admin checks before deciding an amount.
    $this->getJson("/api/v1/admin/sellers/{$seller}/balance")
        ->assertOk()
        ->assertJsonPath('data.balance_minor', 6_000);
});

it('refuses the API to a customer, and 404s a non-uuid payout', function (): void {
    $this->seedRolesAndPermissions();

    $customer = App\Models\Customer::factory()->create();

    $this->actingAs($customer, 'customer');

    // There is no seller- or customer-facing payout surface in v1.
    $this->postJson('/api/v1/admin/payouts', [
        'seller_id' => (string) Str::uuid(),
        'amount_minor' => 100,
    ])->assertForbidden();

    $admin = payoutAdmin();
    $admin->assignRole(config('marketplace.roles.super_admin'));
    $this->actingAs($admin, 'admin');

    /*
     * THE UUID-CAST TRAP, SIXTH WATCH. `payouts.uuid` and `seller_org_uuid` are
     * native uuid columns on PostgreSQL, so a non-uuid segment would be
     * SQLSTATE[22P02] — a 500 rather than a miss.
     */
    $this->postJson('/api/v1/admin/payouts/not-a-uuid/settle', ['outcome' => 'paid'])
        ->assertNotFound();

    $this->getJson('/api/v1/admin/sellers/not-a-uuid/balance')->assertNotFound();
});
