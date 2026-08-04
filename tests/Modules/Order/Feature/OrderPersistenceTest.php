<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Contracts\OrderNumberGeneratorContract;
use App\Modules\Order\Domain\Contracts\OrderRepositoryContract;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Order\Domain\Models\CartItem;
use App\Modules\Order\Domain\Models\CustomerAddress;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The schema and the persistence vocabulary (§2, §3.5)
|--------------------------------------------------------------------------
|
| What is worth pinning at this layer is mostly what the SCHEMA refuses, because
| those are the guarantees no amount of careful application code can substitute
| for:
|
|  1. ONE CART PER CUSTOMER, and one line per offer within it. Two baskets for one
|     shopper is a state with no correct resolution — whichever checkout picked,
|     the other's contents vanish.
|  2. AN ORDER LINE CANNOT BE CHANGED OR DELETED (ADR-053). Enforced in the model,
|     because a financial record that can be edited is not one.
|  3. NO CROSS-MODULE FOREIGN KEYS. An order references five other contexts and
|     constrains none of them — a database-level FK would be the import
|     `LayeringTest` forbids, wearing a different hat.
|  4. THE MONEY COLUMNS ARE INTEGERS and the tax rate is DECIMAL (#6, ADR-005).
|
| Plus the two questions the module's grain makes first-class: "the orders of this
| purchase" and "the holds that have run out".
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/*
|--------------------------------------------------------------------------
| Schema guarantees
|--------------------------------------------------------------------------
*/

it('refuses a second cart for the same customer', function (): void {
    Cart::factory()->forCustomer(7, 'musteri-uuid')->create();

    // Whichever basket checkout picked, the other's contents would vanish
    // silently. The index is what makes that unrepresentable.
    expect(fn () => Cart::factory()->forCustomer(7, 'musteri-uuid')->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses a second line for the same offer in one basket', function (): void {
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->create(['offer_uuid' => 'teklif-1']);

    // Adding it again raises the quantity instead (§2.1) — two lines for one
    // thing is a basket a customer cannot reason about, and a checkout that would
    // reserve twice against the same (org, variant).
    expect(fn () => CartItem::factory()->for($cart)->create(['offer_uuid' => 'teklif-1']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('lets two different customers hold the same offer', function (): void {
    CartItem::factory()->for(Cart::factory()->forCustomer(1, 'a'))->create(['offer_uuid' => 'teklif-1']);
    CartItem::factory()->for(Cart::factory()->forCustomer(2, 'b'))->create(['offer_uuid' => 'teklif-1']);

    // The uniqueness is per BASKET, not global — two shoppers wanting the same
    // thing is the ordinary case, and it is what reservations arbitrate.
    expect(CartItem::query()->where('offer_uuid', 'teklif-1')->count())->toBe(2);
});

it('refuses two orders with the same number', function (): void {
    Order::factory()->create(['order_number' => 'SP-260730-K7M4XB']);

    // A support agent searching a number must get exactly one order.
    expect(fn () => Order::factory()->create(['order_number' => 'SP-260730-K7M4XB']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('lets many orders share one checkout group — that is the whole point', function (): void {
    $group = (string) Str::uuid();

    Order::factory()->inCheckoutGroup($group)->forSeller('satici-a')->create();
    Order::factory()->inCheckoutGroup($group)->forSeller('satici-b')->create();

    // ADR-052's grain: one purchase, N seller orders. The column is indexed, not
    // unique, and a test says so because "unique" is the reflex.
    expect(Order::query()->inCheckoutGroup($group)->count())->toBe(2);
});

it('keeps every other module out of the schema', function (): void {
    /*
     * Order references the customer, the offer, the variant, the product, the
     * seller org and the store — and constrains none of them. A database-level FK
     * would be the import `LayeringTest` forbids, and would let another module's
     * cascade decide an order's lifetime.
     */
    foreach (['orders', 'order_lines', 'cart_items', 'carts'] as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            expect($foreignKey['foreign_table'])->toBeIn(
                ['carts', 'orders', 'currencies'],
                "{$table} constrains {$foreignKey['foreign_table']}, which belongs to another context",
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| The immutable line (ADR-053)
|--------------------------------------------------------------------------
*/

it('refuses to update or delete an order line', function (): void {
    $line = OrderLine::factory()->priced(10_000, 2)->create();

    $line->update(['unit_price_minor' => 1]);
    $line->delete();

    // A financial record that can be edited is not one. There is no "correct a
    // line" path and there should not be: a wrong order is cancelled and
    // re-placed, which leaves both facts on the record.
    expect($line->fresh()->unit_price_minor)->toBe(10_000)
        ->and(OrderLine::query()->whereKey($line->getKey())->exists())->toBeTrue();
});

it('stores money as integers and the tax rate as a decimal string', function (): void {
    $line = OrderLine::factory()->priced(12_990, 3, '0.1000')->create();

    expect($line->unit_price_minor)->toBeInt()
        ->and($line->line_total_minor)->toBe(38_970)
        // A STRING at the column's scale — never a float, which is exactly what
        // DECIMAL was chosen to avoid (ADR-005).
        ->and($line->fresh()->tax_rate)->toBe('0.1000');
});

it('derives the net of a line without storing a fourth number', function (): void {
    $line = OrderLine::factory()->priced(12_000, 1, '0.2000')->create();

    // 12000 KDV-included at %20 → 10000 net, 2000 tax.
    expect($line->line_tax_minor)->toBe(2_000)
        ->and($line->netTotalMinor())->toBe(10_000);
});

it('sums an order’s totals from its lines', function (): void {
    $order = Order::factory()->create();
    OrderLine::factory()->for($order)->priced(10_000, 2, '0.2000')->create();
    OrderLine::factory()->for($order)->priced(5_000, 1, '0.2000')->create();

    $totals = $order->load('lines')->computedTotals();

    /*
     * The invariant totals are written from (§3.5).
     *
     * THE TAX IS SUMMED PER LINE, NOT RECOMPUTED ON THE TOTAL, and the two differ
     * by a kuruş here: 20 000 at %20 rounds to 3 333 and 5 000 to 833 (4 166),
     * while extracting from 25 000 in one go gives 4 167. Per-line is the correct
     * one — lines may carry DIFFERENT rates (a %1 book beside a %20 kettle), so an
     * order-level extraction has no single rate to use, and the invoice has to
     * show tax per line anyway.
     */
    expect($totals['items'])->toBe(25_000)
        ->and($totals['grand'])->toBe(25_000)
        ->and($totals['tax'])->toBe(3_333 + 833);
});

/*
|--------------------------------------------------------------------------
| The repositories
|--------------------------------------------------------------------------
*/

it('creates a basket only when something is actually added', function (): void {
    $carts = app(CartRepositoryContract::class);

    // Rendering a cart badge must not write a row for every visitor who glances
    // at the header.
    expect($carts->forCustomer(42))->toBeNull();

    $cart = $carts->firstOrCreateForCustomer(42, 'musteri-uuid');

    expect($cart->customer_id)->toBe(42)
        // Second call finds the same basket rather than racing itself into the
        // unique index.
        ->and($carts->firstOrCreateForCustomer(42, 'musteri-uuid')->getKey())->toBe($cart->getKey());
});

it('never hands one customer another customer’s basket line', function (): void {
    $carts = app(CartRepositoryContract::class);

    $mine = Cart::factory()->forCustomer(1, 'a')->create();
    $theirs = Cart::factory()->forCustomer(2, 'b')->create();
    $theirItem = CartItem::factory()->for($theirs)->create();

    // Scoped to the cart, not looked up by uuid alone — guessing a line uuid gets
    // null rather than somebody else's item.
    expect($carts->findItem($mine->fresh(), $theirItem->uuid))->toBeNull();
});

it('empties a basket without destroying it', function (): void {
    $carts = app(CartRepositoryContract::class);
    $cart = Cart::factory()->create();
    CartItem::factory()->for($cart)->count(3)->create();

    $carts->clear($cart->load('items'));

    /*
     * The CART survives because the customer will shop again and a row per
     * purchase is churn; the ITEMS go because they are now order lines, and
     * leaving them would let the same basket be checked out twice.
     */
    expect(Cart::query()->whereKey($cart->getKey())->exists())->toBeTrue()
        ->and($cart->items)->toHaveCount(0)
        ->and(CartItem::query()->where('cart_id', $cart->getKey())->count())->toBe(0);
});

it('gives back the same null for “not yours” and “does not exist”', function (): void {
    $addresses = app(CustomerAddressRepositoryContract::class);
    $theirs = CustomerAddress::factory()->forCustomer(2, 'b')->create();

    // An attacker must not be able to use the difference to confirm an address
    // uuid is real.
    expect($addresses->findForCustomer($theirs->uuid, 1))->toBeNull()
        ->and($addresses->findForCustomer('yok-boyle-bir-adres', 1))->toBeNull();
});

it('clears a previous default when another takes over', function (): void {
    $addresses = app(CustomerAddressRepositoryContract::class);

    $old = CustomerAddress::factory()->forCustomer(5, 'e')->defaultShipping()->create();
    $new = CustomerAddress::factory()->forCustomer(5, 'e')->create();

    $addresses->clearDefault(5, 'is_default_shipping', (int) $new->getKey());

    // The action's half of "one default per purpose" — the partial unique index
    // exists only on PostgreSQL, so this is what the suite can exercise on both.
    expect($old->fresh()->is_default_shipping)->toBeFalse();
});

it('refuses to clear a column that is not one of the two flags', function (): void {
    $addresses = app(CustomerAddressRepositoryContract::class);
    $address = CustomerAddress::factory()->forCustomer(5, 'e')->create(['city' => 'İzmir']);

    // This method writes a column NAME into a query — the one place a repository
    // looks innocent and is not.
    $addresses->clearDefault(5, 'city');

    expect($address->fresh()->city)->toBe('İzmir');
});

it('reads a whole purchase, in order', function (): void {
    $orders = app(OrderRepositoryContract::class);
    $group = (string) Str::uuid();

    $first = Order::factory()->inCheckoutGroup($group)->forSeller('satici-a')->create();
    $second = Order::factory()->inCheckoutGroup($group)->forSeller('satici-b')->create();
    Order::factory()->create();

    expect($orders->forCheckoutGroup($group)->pluck('uuid')->all())
        ->toBe([$first->uuid, $second->uuid]);
});

it('finds the holds that have run out, oldest first and bounded', function (): void {
    $orders = app(OrderRepositoryContract::class);

    $expired = Order::factory()->expired()->create();
    Order::factory()->create();                      // fresh, still within the window
    Order::factory()->expired()->placed()->create(); // committed — has no expiry
    Order::factory()->expired()->cancelled()->create();

    /*
     * ONLY `Pending` ORDERS EXPIRE. A placed order's stock has committed and a
     * cancelled one has already been released — sweeping either would release a
     * hold that no longer exists, or worse, take back stock that was sold.
     */
    expect($orders->expiredPending()->pluck('uuid')->all())->toBe([$expired->uuid]);
});

it('agrees with the order about what expired means', function (): void {
    $expired = Order::factory()->expired()->create();
    $fresh = Order::factory()->create();

    // The sweep and the model must not disagree: one releasing a hold the other
    // still shows as live is the worst kind of drift.
    expect($expired->reservationHasExpired())->toBeTrue()
        ->and($fresh->reservationHasExpired())->toBeFalse()
        // A committed order has no expiry at all.
        ->and(Order::factory()->expired()->placed()->create()->reservationHasExpired())->toBeFalse();
});

it('answers whether an order is this customer’s', function (): void {
    $orders = app(OrderRepositoryContract::class);
    $mine = Order::factory()->forCustomer(9, 'benim')->create();

    expect($orders->belongsToCustomer($mine->uuid, 9))->toBeTrue()
        ->and($orders->belongsToCustomer($mine->uuid, 10))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The order number
|--------------------------------------------------------------------------
*/

it('generates a dated, readable, non-sequential order number', function (): void {
    $number = app(OrderNumberGeneratorContract::class)->generate();

    expect($number)->toStartWith('SP-'.now()->format('ymd').'-');

    // The alphabet of the RANDOM part drops 0/O and 1/I, because this number is
    // read aloud and typed back at least once in its life. (The date naturally
    // contains digits, which nobody transcribes as letters.)
    $code = Str::afterLast($number, '-');

    expect($code)->toHaveLength(6)
        ->and($code)->not->toMatch('/[01OI]/');
});

it('does not tell every customer how many orders the platform has taken', function (): void {
    $generator = app(OrderNumberGeneratorContract::class);

    $numbers = collect(range(1, 20))->map(fn (): string => $generator->generate());

    // Random, not sequential: a counter would let anyone enumerate the range, and
    // this number gets PRINTED on invoices and pasted into support chats.
    expect($numbers->unique())->toHaveCount(20);
});

/*
|--------------------------------------------------------------------------
| The Core read port (§5)
|--------------------------------------------------------------------------
*/

it('answers the four questions Payment will ask', function (): void {
    $query = app(OrderQueryContract::class);
    $group = (string) Str::uuid();

    $order = Order::factory()->inCheckoutGroup($group)->placed()->totalling(25_000, 4_167)->create();
    $sibling = Order::factory()->inCheckoutGroup($group)->create();

    expect($query->orderExists($order->uuid))->toBeTrue()
        ->and($query->orderExists('yok'))->toBeFalse()
        // A STRING, not the enum — typing the port with it would make every
        // consumer import the module the port exists to avoid importing.
        ->and($query->orderStatus($order->uuid))->toBe(OrderStatus::AwaitingPayment->value)
        // THE METHOD THE SPLIT MAKES NECESSARY: a customer pays once for what the
        // platform stores as N orders.
        ->and($query->ordersForCheckoutGroup($group))->toBe([$order->uuid, $sibling->uuid]);

    $totals = $query->orderTotals($order->uuid);

    expect($totals['grand_total_minor'])->toBe(25_000)
        ->and($totals['tax_total_minor'])->toBe(4_167)
        ->and($totals['currency_code'])->toBeString();
});

it('returns nothing rather than guessing for an order that does not exist', function (): void {
    $query = app(OrderQueryContract::class);

    expect($query->orderStatus('yok'))->toBeNull()
        ->and($query->orderTotals('yok'))->toBeNull()
        ->and($query->ordersForCheckoutGroup('yok'))->toBe([]);
});
