<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockItem;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Application\Jobs\ExpireAwaitingPaymentJob;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderExpired;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The payment window (ADR-072) — the sweep that keeps sellers sellable
|--------------------------------------------------------------------------
|
| **THIS IS A REGRESSION TEST FOR A LIVE BUG**, not a hypothetical. ADR-057 made
| placement HOLD a reservation and Payment commit it, and nothing released the
| hold when a customer closed the tab at the card form. The hold sat forever, the
| seller's `available = on_hand − reserved` fell toward zero, and their offer
| dropped off the buy box WHILE STILL DECLARING STOCK.
|
| So the assertion that matters is not the status — it is that **availability
| comes back**.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A placed, unpaid order for one unit, plus the seller's stock pool.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{order: Order, stock: StockItem}
 */
function placedUnpaidOrder(int $quantity = 1, int $onHand = 10): array
{
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
        priceMinor: 12_000,
        stockQuantity: $onHand,
    ));

    app(AddCartItemAction::class)->run(1, 'musteri', new AddCartItemDTO(
        offerUuid: $offer->uuid,
        quantity: $quantity,
    ));

    $address = app(CreateCustomerAddressAction::class)->run(1, 'musteri', new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    $orders = app(CheckoutAction::class)->run(1, 'musteri', new CheckoutDTO(
        shippingAddressUuid: $address->uuid,
        billingAddressUuid: $address->uuid,
    ));

    app(PlaceOrderAction::class)->run($orders[0]->checkout_group_uuid);

    /** @var Order $order */
    $order = Order::query()->where('uuid', $orders[0]->uuid)->firstOrFail();

    /** @var StockItem $stock */
    $stock = StockItem::query()
        ->where('selling_org_uuid', $organization->uuid)
        ->where('variant_uuid', $variant->uuid)
        ->firstOrFail();

    return ['order' => $order->fresh(), 'stock' => $stock];
}

it('gives a seller their availability back when nobody pays', function (): void {
    Event::fake([OrderExpired::class]);
    $fixture = placedUnpaidOrder(quantity: 2, onHand: 10);

    /*
     * **THE BUG, REPRODUCED.** Placement held two units, so the seller can sell
     * eight — and until this sweep existed, that stayed true forever if the
     * customer never came back.
     */
    expect($fixture['stock']->fresh()->reserved)->toBe(2)
        ->and($fixture['stock']->fresh()->available())->toBe(8);

    // The shopper closes the tab. Six minutes pass; the window is five.
    $this->travel(6)->minutes();

    app(ExpireAwaitingPaymentJob::class)->handle(
        app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(App\Modules\Order\Application\Actions\ExpireOrderAction::class),
    );

    $order = $fixture['order']->fresh();
    $stock = $fixture['stock']->fresh();

    expect($order->status)->toBe(OrderStatus::Expired)
        /*
         * **THE ASSERTION THAT MATTERS.** Not the status — the availability. The
         * seller can sell all ten again, and their offer goes back on the buy
         * box.
         */
        ->and($stock->reserved)->toBe(0)
        ->and($stock->available())->toBe(10)
        // ON-HAND UNTOUCHED: the units never left, a hold only lowers
        // `available` (ADR-048), so releasing invents no stock.
        ->and($stock->on_hand)->toBe(10);

    Event::assertDispatched(OrderExpired::class);
});

it('leaves an order still inside its window alone', function (): void {
    $fixture = placedUnpaidOrder();

    // Four minutes into a five-minute window: the customer may still be typing a
    // card number, and their hold is theirs.
    $this->travel(4)->minutes();

    app(ExpireAwaitingPaymentJob::class)->handle(
        app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(App\Modules\Order\Application\Actions\ExpireOrderAction::class),
    );

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($fixture['stock']->fresh()->reserved)->toBe(1);
});

it('never touches an order that was paid', function (): void {
    $fixture = placedUnpaidOrder();

    // The callback landed. The commit already happened; the hold is spent, not
    // held.
    $fixture['order']->forceFill(['status' => OrderStatus::Paid])->save();

    $this->travel(30)->minutes();

    app(ExpireAwaitingPaymentJob::class)->handle(
        app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(App\Modules\Order\Application\Actions\ExpireOrderAction::class),
    );

    /*
     * **THE RACE THE GUARD EXISTS FOR.** The sweep reads a batch and then acts
     * row by row, so a callback can land in between — and re-expiring a paid
     * order is the one outcome that could undo a commit.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Paid);
});

it('is idempotent — a second sweep changes nothing', function (): void {
    $fixture = placedUnpaidOrder(quantity: 3, onHand: 10);

    $this->travel(6)->minutes();

    $run = function (): void {
        app(ExpireAwaitingPaymentJob::class)->handle(
            app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
            app(App\Modules\Order\Application\Actions\ExpireOrderAction::class),
        );
    };

    $run();
    $run();

    /*
     * THE SECOND RUN FINDS NOTHING — the order is no longer `AwaitingPayment`,
     * so the repository does not return it and the action's guard would refuse
     * it anyway. Releasing twice would not double-count either (Inventory's
     * `release()` is a no-op on a released hold), which is belt and braces.
     */
    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($fixture['stock']->fresh()->reserved)->toBe(0)
        ->and($fixture['stock']->fresh()->available())->toBe(10);
});

it('reads the window from settings, so an operator can lengthen it', function (): void {
    // `settings()->set()` NO-OPS ON AN UNREGISTERED KEY — it edits a row, it does
    // not create one — so the seeder that registers the default has to have run.
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    $fixture = placedUnpaidOrder();

    // Support reports churn: shoppers are losing orders mid-3-D-Secure.
    settings()->set('order.payment_window_minutes', 30);

    expect(ExpireAwaitingPaymentJob::paymentWindowMinutes())->toBe(30);

    // Ten minutes in — past the shipped default, well inside the new window.
    $this->travel(10)->minutes();

    app(ExpireAwaitingPaymentJob::class)->handle(
        app(App\Modules\Order\Domain\Contracts\OrderRepositoryContract::class),
        app(App\Modules\Order\Application\Actions\ExpireOrderAction::class),
    );

    expect($fixture['order']->fresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

it('floors the window at a minute, whatever an operator types', function (): void {
    $this->seed(Database\Modules\Settings\Seeders\SettingsSeeder::class);

    settings()->set('order.payment_window_minutes', 0);

    /*
     * A WINDOW OF ZERO WOULD EXPIRE AN ORDER IN THE SAME BREATH AS PLACING IT,
     * before the customer ever reached the card form — a setting that can empty
     * every basket on the platform deserves a floor in code rather than trust.
     */
    expect(ExpireAwaitingPaymentJob::paymentWindowMinutes())->toBe(1);
});
