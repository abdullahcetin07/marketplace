<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\Enums\LedgerEntryType;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Enums\PayoutStatus;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Models\SellerLedgerEntry;
use App\Modules\Payment\Presentation\Filament\Resources\CommissionRuleResource\Pages\ListCommissionRules;
use App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource\Pages\ListPayments;
use App\Modules\Payment\Presentation\Filament\Resources\PayoutResource\Pages\CreatePayout;
use App\Modules\Payment\Presentation\Filament\Resources\PayoutResource\Pages\ListPayouts;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The finance screens, actually rendered (Payment.md §8)
|--------------------------------------------------------------------------
|
| **THIS FILE EXISTS BECAUSE A GREEN SUITE SHIPPED A BLANK PAGE.** Every earlier
| Payment test drove an action, an API endpoint or a policy. None of them ever
| RENDERED a table, so nothing noticed that the amount column reads
| `$record->currency->code` on a query that never eager-loads it — which under
| `Model::shouldBeStrict()` is not a slow page, it is a
| `LazyLoadingViolationException` and a screen an admin cannot open at all. It
| shipped that way on two resources.
|
| So these tests assert COLUMN STATE, not just a 200: evaluating the column is
| what touches the relation, and a page that renders its chrome while every row
| throws would still pass a smoke test that only checked the status code.
|
| **AND EVERY TABLE FIXTURE HERE SEEDS AT LEAST TWO ROWS**, which is not padding.
| Laravel only ARMS the lazy-loading guard when a query hydrates more than one
| model — `Builder::hydrate()` sets `$model->preventsLazyLoading` behind
| `count($items) > 1`, on the reasoning that a single row cannot be an N+1. So a
| one-record smoke test renders the offending column happily and proves nothing;
| the first version of this file did exactly that and passed with the bug still
| in place. Verified the other way round, too: removing either eager load fails
| these tests.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Grant an admin the finance screens.
 *
 * TAKES THE ADMIN RATHER THAN RESOLVING ONE, which is the shape every other panel
 * test here uses: `$this` only exists inside the test closure, and a Pest helper
 * function is not bound to the test case.
 *
 * Named for this file because Pest shares ONE global function namespace.
 */
function asFinanceAdmin(App\Models\Admin $admin): App\Models\Admin
{
    $admin->assignRole(config('marketplace.roles.super_admin'));

    return $admin;
}

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
*/

it('renders the payments table with its money column', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $payment = Payment::factory()->create([
        'amount_minor' => 159_000,
        'status' => PaymentStatus::Paid,
    ]);

    // THE SECOND ROW IS WHAT ARMS THE GUARD — see the note at the top of the file.
    Payment::factory()->create(['status' => PaymentStatus::Pending]);

    /*
     * THE ASSERTION THAT WOULD HAVE CAUGHT THE BUG. `assertTableColumnStateSet`
     * evaluates the column's own closure, which is where `$record->currency` is
     * read — a plain `assertOk()` renders the page chrome and never touches it.
     */
    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$payment])
        ->assertTableColumnStateSet('amount_minor', '1.590,00 '.$payment->currency->code, $payment);
});

it('offers the refund button only where there is money to give back', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $paid = Payment::factory()->create(['status' => PaymentStatus::Paid]);
    $pending = Payment::factory()->create(['status' => PaymentStatus::Pending]);
    $refunded = Payment::factory()->create(['status' => PaymentStatus::Refunded]);

    $page = Livewire::test(ListPayments::class);

    // A pending payment collected nothing and a fully refunded one has nothing
    // left — showing a button that would be refused is worse than showing none.
    $page->assertTableActionVisible('refund', $paid)
        ->assertTableActionHidden('refund', $pending)
        ->assertTableActionHidden('refund', $refunded);
});

it('lets nobody create, edit or delete a payment', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid]);

    // A payment is a record of what a bank did; editing it would make it a record
    // of what somebody typed.
    Livewire::test(ListPayments::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect(App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource::canCreate())->toBeFalse()
        ->and(App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource::canEdit($payment))->toBeFalse()
        ->and(App\Modules\Payment\Presentation\Filament\Resources\PaymentAdminResource::canDelete($payment))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Payouts — the same column, the same latent bug
|--------------------------------------------------------------------------
*/

it('renders the payouts table with its money column', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $payout = Payout::factory()->create([
        'amount_minor' => 98_400,
        'status' => PayoutStatus::Pending,
    ]);

    Payout::factory()->create(['status' => PayoutStatus::Paid]);

    /*
     * FOUND BY LOOKING, NOT BY CRASHING. This screen had the identical unguarded
     * `$record->currency->code` and had simply never been opened since P4 shipped
     * — its tests exercised the API and the actions.
     */
    Livewire::test(ListPayouts::class)
        ->assertCanSeeTableRecords([$payout])
        ->assertTableColumnStateSet('amount_minor', '984,00 '.$payout->currency->code, $payout);
});

it('shows the settle action only while a payout is pending', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $pending = Payout::factory()->create(['status' => PayoutStatus::Pending]);
    $paid = Payout::factory()->create(['status' => PayoutStatus::Paid]);

    // `Payout::isSettling()` refuses a write out of any other state, so offering
    // the button there would be offering a refusal.
    Livewire::test(ListPayouts::class)
        ->assertTableActionVisible('settle', $pending)
        ->assertTableActionHidden('settle', $paid);
});

it('renders the payout form, whose balance hint is a SUM and not a relation', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    $seller = (string) Illuminate\Support\Str::uuid();

    SellerLedgerEntry::factory()->forSeller($seller)->of(LedgerEntryType::SaleCredit, 9_840)->create();

    /*
     * THE ONE PLACE A BALANCE APPEARS IN THE PANEL. It is read live from the
     * ledger (ADR-062 — there is no balance column to bind to), so this proves the
     * form renders AND that the hint's query survives the same strict mode the
     * tables just did.
     */
    Livewire::test(CreatePayout::class)
        ->fillForm(['seller_org_uuid' => $seller, 'amount_minor' => '98.40'])
        ->assertHasNoFormErrors();

    expect(SellerLedgerEntry::balanceFor($seller))->toBe(9_840);
});

/*
|--------------------------------------------------------------------------
| The third finance screen
|--------------------------------------------------------------------------
*/

it('renders the commission rules table', function (): void {
    asFinanceAdmin($this->actingAsAdmin());

    // The only WRITABLE surface in this module (ADR-061): a rate is configuration,
    // unlike a payment. It touches no relation — asserted by rendering rather than
    // by reading the class.
    Livewire::test(ListCommissionRules::class)->assertOk();
});
