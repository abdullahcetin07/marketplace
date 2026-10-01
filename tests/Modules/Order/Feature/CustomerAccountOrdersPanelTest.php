<?php

declare(strict_types=1);

use App\Core\Domain\Accounts\AccountPanelContributorContract;
use App\Core\Support\AccountPanelRegistry;
use App\Models\Customer;
use App\Modules\Identity\Presentation\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Presentation\Filament\Widgets\CustomerOrdersWidget;
use App\Shared\Enums\UserType;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A shopper's orders, on their account page (§17)
|--------------------------------------------------------------------------
|
| An admin answering "what did this person order" had to leave the account page
| and search a name in the orders list. Identity owns that page and is FROZEN, so
| the answer is the composition seam the storefront already uses (ADR-036):
| Order registers a panel, Identity renders whatever is registered and names no
| module.
|
| What is pinned: the panel reaches the page, it shows only THIS shopper, it is
| absent from account types it does not belong on, and a contributor that breaks
| costs its own panel rather than the page.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('puts Order’s panel on a customer page without Identity naming it', function (): void {
    expect(AccountPanelRegistry::widgetsFor(UserType::Customer))
        ->toContain(CustomerOrdersWidget::class);

    /*
     * AND NOWHERE ELSE. A shopper's orders have no business on a staff page, and
     * a seller's own purchases are a different question.
     */
    expect(AccountPanelRegistry::widgetsFor(UserType::Admin))->not->toContain(CustomerOrdersWidget::class)
        ->and(AccountPanelRegistry::widgetsFor(UserType::Seller))->not->toContain(CustomerOrdersWidget::class);
});

it('shows this shopper’s orders and nobody else’s', function (): void {
    $this->actingAsAdmin()->syncRoles([config('marketplace.roles.admin')]);

    /** @var Customer $mine */
    $mine = Customer::factory()->create();
    /** @var Customer $theirs */
    $theirs = Customer::factory()->create();

    $ours = Order::factory()->create(['customer_id' => $mine->getKey(), 'customer_uuid' => $mine->uuid]);
    $other = Order::factory()->create(['customer_id' => $theirs->getKey(), 'customer_uuid' => $theirs->uuid]);

    // Matched on `customer_uuid` rather than a relation: an order holds the
    // shopper as a bare uuid (ADR-040) so the two modules share no foreign key.
    Livewire::test(CustomerOrdersWidget::class, ['record' => $mine])
        ->assertCanSeeTableRecords([$ours])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows every status, unlike the seller’s list', function (): void {
    $this->actingAsAdmin()->syncRoles([config('marketplace.roles.admin')]);

    /** @var Customer $customer */
    $customer = Customer::factory()->create();

    $expired = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'customer_uuid' => $customer->uuid,
        'status' => 'expired',
    ]);

    /*
     * A seller sees only what became a sale (§16) because the rest is not their
     * work. An admin here is answering a question about a person, and "they
     * tried and it never went through" is very often the answer.
     */
    Livewire::test(CustomerOrdersWidget::class, ['record' => $customer])
        ->assertCanSeeTableRecords([$expired]);
});

it('shows nothing at all without a record, never every order on the platform', function (): void {
    $this->actingAsAdmin()->syncRoles([config('marketplace.roles.admin')]);

    $someone = Order::factory()->create();

    Livewire::test(CustomerOrdersWidget::class)
        ->assertCanNotSeeTableRecords([$someone]);
});

it('opens the account page even when a contributor is broken', function (): void {
    /** @var Customer $customer */
    $customer = Customer::factory()->create();
    $this->actingAsAdmin()->syncRoles([config('marketplace.roles.admin')]);

    AccountPanelRegistry::register(BrokenAccountPanel::class);

    /*
     * The registry skips what it cannot build, so the working panel still
     * reaches the page. This is the assertion the guard exists for.
     */
    expect(AccountPanelRegistry::widgetsFor(UserType::Customer))
        ->toContain(CustomerOrdersWidget::class)
        // One panel registered, one broken, one rendered.
        ->and(AccountPanelRegistry::widgetsFor(UserType::Customer))->toHaveCount(1);

    // And the page itself opens — it is where an admin goes to answer a
    // question about a person, and a broken panel must not close it.
    Livewire::test(ViewCustomer::class, ['record' => $customer->uuid])->assertOk();
});

/**
 * A contributor that breaks where a real one would: deciding which class to
 * render. A module half-removed, a widget renamed, a config it needed gone.
 */
final class BrokenAccountPanel implements AccountPanelContributorContract
{
    public function appliesTo(UserType $type): bool
    {
        return true;
    }

    public function widget(): string
    {
        throw new RuntimeException('a module that cannot name its widget');
    }

    public function sortOrder(): int
    {
        return 0;
    }
}
