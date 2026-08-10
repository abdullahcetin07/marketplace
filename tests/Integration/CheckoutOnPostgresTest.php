<?php

declare(strict_types=1);

use App\Console\Commands\ResetCommerceCommand;
use App\Core\Domain\Contracts\OrderCancellationContract;
use App\Models\Admin;
use App\Models\Customer;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Inventory\Domain\Models\StockReservation;
use App\Modules\Localization\Domain\Contracts\GeoRepositoryContract;
use App\Modules\Localization\Domain\Models\GeoProvince;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\CancelOrderAction;
use App\Modules\Order\Application\Actions\CheckoutAction;
use App\Modules\Order\Application\Actions\CreateCustomerAddressAction;
use App\Modules\Order\Application\Actions\PlaceOrderAction;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\CancelOrderDTO;
use App\Modules\Order\Domain\DTOs\CheckoutDTO;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Shipping\Domain\Enums\ShipmentStatus;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Checkout, on the database production actually runs (ADR-049 amendment)
|--------------------------------------------------------------------------
|
| THIS FILE EXISTS BECAUSE THE REST OF THE SUITE CANNOT SEE THIS CLASS OF BUG.
| Every other test runs on SQLite `:memory:`, which is fast, hermetic and — for
| column types — permissive to the point of dishonesty: a `uuid` column there is
| just text, so it accepts anything.
|
| PostgreSQL does not. When ADR-057 made the reservation reference per line
| (`{order_uuid}:{variant_uuid}`), that composite went into a native `uuid`
| column, and **every checkout 500'd in production while 1 088 tests stayed
| green**. Nothing in the suite could have caught it, because nothing in the suite
| ran on a database that enforces a type.
|
| So this is a small, deliberate exception to "tests run on SQLite": one happy path
| through the WHOLE reservation lifecycle — reserve, commit, release — on the real
| engine, asserting the things only that engine can refuse.
|
| IT WRAPS EVERYTHING IN A TRANSACTION AND ROLLS BACK (`DatabaseTransactions`,
| not `RefreshDatabase`). It runs against a real, already-migrated database, so it
| must leave no trace and must never drop a table. That also means it needs no
| migration run of its own and costs a second, not a minute.
|
| IT SKIPS ITSELF when PostgreSQL is unreachable, so a laptop with no database and
| CI without a service container both stay green rather than red for the wrong
| reason. A skipped guard is a weaker guard — but a guard everybody disables
| because it fails locally is no guard at all.
|
*/

uses(DatabaseTransactions::class);

/**
 * One connection setting, read from the real environment.
 *
 * `getenv()` RATHER THAN `env()`, deliberately and not merely to satisfy the
 * analyser: `env()` reads the framework's loaded environment, which in tests is
 * `.env.testing` — the file that points everything at SQLite and is precisely
 * what this test must not believe. The process environment is the one place a CI
 * container's real database settings arrive.
 */
function pgsqlSetting(string $key, ?string $fallbackKey, string $default): string
{
    foreach (array_filter([$key, $fallbackKey]) as $candidate) {
        $value = getenv($candidate);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return $default;
}

beforeEach(function (): void {
    /*
    | THE CONNECTION IS BUILT HERE, NOT READ FROM `.env.testing`.
    |
    | That file points every connection at SQLite `:memory:` — which is the whole
    | reason this test exists — so reading it would hand the pgsql driver a
    | database literally named ":memory:". The values below are the repo's own
    | committed dev defaults (see `docker-compose.yml`), each overridable by the
    | environment so CI can point at its own service container.
    */
    config([
        'database.connections.pgsql.host' => pgsqlSetting('PGSQL_TEST_HOST', 'DB_HOST', '127.0.0.1'),
        'database.connections.pgsql.port' => pgsqlSetting('PGSQL_TEST_PORT', 'DB_PORT', '5432'),
        'database.connections.pgsql.database' => pgsqlSetting('PGSQL_TEST_DATABASE', null, 'marketplaceos'),
        'database.connections.pgsql.username' => pgsqlSetting('PGSQL_TEST_USERNAME', null, 'marketplaceos'),
        'database.connections.pgsql.password' => pgsqlSetting('PGSQL_TEST_PASSWORD', null, 'secret'),
    ]);

    DB::purge('pgsql');

    // The whole file is about a driver, so the driver is the first thing checked.
    try {
        DB::connection('pgsql')->getPdo();
    } catch (\Throwable $exception) {
        $this->markTestSkipped('PostgreSQL is not reachable: '.$exception->getMessage());
    }

    // Run everything on pgsql for the rest of the test, including the models'
    // implicit connection.
    config(['database.default' => 'pgsql']);
    DB::setDefaultConnection('pgsql');

    /*
    | **EVERY TEST IN THIS FILE RUNS INSIDE A TRANSACTION THAT IS ROLLED BACK.**
    |
    | `tests/Pest.php` has always claimed "each file wraps itself in a transaction
    | instead" — and this file did not. It pointed the DEFAULT connection at the
    | real database and wrote factory rows straight into it, so every suite run
    | left organizations, products, orders and tax brackets behind. By the time
    | anybody looked there were 114 KDV brackets, 110 of them named `KDV 0.2000`
    | with codes like `kdv-28239`, and a "live" catalogue that was mostly faker
    | output — which is worse than clutter, because it gets READ as real data and
    | reported as real findings.
    |
    | `RefreshDatabase` is not the answer here and never was: it drops and
    | re-migrates, which is exactly what must never happen to a database somebody
    | else is using. A transaction gives the same isolation and touches nothing.
    |
    | AFTER the skip check, so an unreachable engine leaves no transaction open.
    */
    DB::connection('pgsql')->beginTransaction();
});

afterEach(function (): void {
    /*
    | ROLLED BACK WHATEVER HAPPENED, including a test that failed halfway. Guarded
    | on the level because a test that skipped never opened one, and because a
    | test that deliberately poisons its own transaction may have unwound it.
    */
    if (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->rollBack();
    }
});

/**
 * A sellable offer plus a customer with an address — the minimum a checkout needs.
 *
 * BUILT ON THE REAL DATABASE, so every column type in the chain is exercised: the
 * catalogue's, the offer's, the stock pool's and the order's.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{offerUuid: string, addressUuid: string, variantUuid: string, orgUuid: string}
 */
function pgsqlCheckoutFixture(int $customerId, string $customerUuid): array
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
        stockQuantity: 10,
    ));

    $address = app(CreateCustomerAddressAction::class)->run($customerId, $customerUuid, new CustomerAddressDTO(
        label: 'Ev',
        recipientName: 'Ayşe Yılmaz',
        phone: '+905551234567',
        line1: 'Bağdat Caddesi 120',
        city: 'İstanbul',
        countryCode: 'TR',
    ));

    return [
        'offerUuid' => $offer->uuid,
        'addressUuid' => $address->uuid,
        'variantUuid' => $variant->uuid,
        'orgUuid' => $organization->uuid,
    ];
}

it('checks out, holds and commits on PostgreSQL', function (): void {
    // A customer id that cannot collide with the dev database's real rows: the
    // order tables reference the customer by id/uuid with no FK (ADR-040), so an
    // invented one is a legitimate value and nothing to clean up.
    $customerId = 900_000 + random_int(1, 90_000);

    /*
     * A REAL uuid, not the readable placeholder the SQLite tests use. `customer_uuid`
     * is a native `uuid` column, and SQLite accepts "musteri-uuid" where PostgreSQL
     * refuses it — the same permissiveness this whole file exists to stop hiding
     * things behind. (It caught this fixture on the first run.)
     */
    $customerUuid = (string) Str::uuid();

    $fixture = pgsqlCheckoutFixture($customerId, $customerUuid);

    app(AddCartItemAction::class)->run($customerId, $customerUuid, new AddCartItemDTO(
        offerUuid: $fixture['offerUuid'],
        quantity: 2,
    ));

    /*
     * THE LINE THAT USED TO THROW. `CheckoutAction` reserves through the Core
     * command contract, and the reference it passes is `{order_uuid}:{variant_uuid}`
     * — which PostgreSQL refused for as long as the column was a native `uuid`.
     * If this test ever fails with SQLSTATE[22P02], the column type and the
     * reference format have drifted apart again.
     */
    $orders = app(CheckoutAction::class)->run($customerId, $customerUuid, new CheckoutDTO(
        shippingAddressUuid: $fixture['addressUuid'],
        billingAddressUuid: $fixture['addressUuid'],
    ));

    expect($orders)->toHaveCount(1);

    $order = $orders[0];
    $reference = $order->reservationReferenceFor($fixture['variantUuid']);

    // The composite is stored VERBATIM and readably — the property the string
    // column was chosen for (ADR-049 amendment).
    $reservation = StockReservation::query()->where('reference', $reference)->sole();

    expect($reservation->reference)->toBe($reference)
        ->and($reference)->toContain(':')
        ->and($reservation->quantity)->toBe(2);

    // Placement holds rather than commits (ADR-057) — asserted here too, because
    // this is the only place the whole lifecycle runs on the real engine.
    app(PlaceOrderAction::class)->run($order->checkout_group_uuid);

    expect($order->fresh()->status->value)->toBe('awaiting_payment');

    // And release, the third verb, on the same key.
    app(CancelOrderAction::class)->run(
        $order->fresh(),
        new CancelOrderDTO(CancelOrderDTO::BY_CUSTOMER, 'pgsql smoke'),
    );

    expect(StockReservation::query()->where('reference', $reference)->sole()->status->value)
        ->toBe('released');
});

it('keeps the reservation reference a string column, not a uuid', function (): void {
    /*
     * THE ASSERTION THAT WOULD HAVE CAUGHT IT DIRECTLY, and it costs one query.
     * A future migration that "tidies" this column back to `uuid` — a reasonable
     * thing to think, given the name it used to have — fails here rather than in
     * production at the first checkout.
     */
    $type = DB::connection('pgsql')->selectOne(
        "select data_type from information_schema.columns
         where table_name = 'stock_reservations' and column_name = 'reference'",
    );

    expect($type)->not->toBeNull('stock_reservations.reference is missing')
        ->and($type->data_type)->toBeIn(['character varying', 'text']);
});

/*
|--------------------------------------------------------------------------
| The same trap, a second time: the geo cascade (ADR-056 amendment)
|--------------------------------------------------------------------------
|
| ADDED 2026-08-03, AFTER THIS EXACT CLASS OF BUG APPEARED AGAIN. The geo
| endpoints resolve a parent by NAME — a saved address holds "İstanbul", not a
| uuid — and the first implementation asked `where('uuid', $value)->orWhere('name',
| $value)`, which is a 500 on PostgreSQL and a silent false on SQLite. It was
| caught by hand, live, exactly like the reservation reference was.
|
| Twice is a pattern, so the guard lives here rather than in a comment: any read
| that accepts user text and touches a `uuid` column gets a case in this file.
*/

it('resolves a province and district BY NAME on PostgreSQL', function (): void {
    $province = GeoProvince::query()
        ->whereHas('country', static fn ($q) => $q->where('iso2', 'TR'))
        ->where('is_active', true)
        ->first();

    if ($province === null) {
        $this->markTestSkipped('TR geography is not seeded on this database.');
    }

    $geo = app(GeoRepositoryContract::class);

    /*
     * THE CALL THAT USED TO THROW SQLSTATE[22P02]. "İstanbul" is not a uuid, and
     * `geo_provinces.uuid` is a NATIVE uuid column here — so a comparison against
     * it is a type error, not a non-match. If this fails with 22P02, somebody has
     * put the uuid comparison back on the unconditional path.
     */
    expect($geo->districts($province->name, 'TR'))->not->toBeEmpty();

    $district = $province->districts()->where('is_active', true)->first();

    if ($district !== null) {
        // Neighbourhoods take TWO user-supplied names, so both resolution paths
        // are exercised.
        $geo->neighborhoods($district->name, $province->name, 'TR');
    }

    // And a genuine uuid still resolves, so the guard did not simply disable the
    // uuid path it was written to protect.
    expect($geo->districts($province->uuid, 'TR'))->not->toBeEmpty();
});

it('keeps a name that is not a uuid away from the uuid column', function (): void {
    // Belt and braces: the raw query, so the failure is unambiguous if the
    // repository is ever refactored into something clever.
    expect(static fn (): mixed => DB::connection('pgsql')
        ->table('geo_provinces')
        ->where('name', 'İstanbul')
        ->first())
        ->not->toThrow(\Throwable::class);
});

/*
|--------------------------------------------------------------------------
| The same trap, a THIRD time: slug-addressed catalog reads (ADR-059)
|--------------------------------------------------------------------------
|
| ADDED 2026-08-03. `?category=Dermokozmetik` and `/products/{slug}` both 500'd in
| production — `where('uuid', <a word>)` again — while the whole SQLite suite
| stayed green, again. Three occurrences of one shape of bug:
|
|   1. ADR-049  the reservation reference; every checkout, in production
|   2. ADR-056  the geo cascade; caught by hand on the live box
|   3. ADR-059  the storefront's own listing filter and product page
|
| The rule the amendment log now carries: ANY read that accepts user text and
| touches a uuid column gets a case in THIS file. These are those cases.
*/

it('takes a slug on every public catalog read without casting it to a uuid', function (): void {
    $product = Product::query()
        ->where('status', 'published')
        ->whereNotNull('slug')
        ->first();

    if ($product === null) {
        $this->markTestSkipped('No published product on this database.');
    }

    /*
     * THE CALL THAT USED TO THROW SQLSTATE[22P02]. `products.uuid` is a NATIVE
     * uuid column here, so comparing it to a slug is a type error rather than a
     * non-match. `PublicKey::looksLikeUuid()` is what keeps the two apart, and if
     * this fails with 22P02 somebody has put the uuid comparison back on the
     * unconditional path.
     */
    $this->getJson('/api/v1/products/'.$product->slug)->assertOk();

    // The listing filters, both of them, with a value that is plainly not a uuid.
    $this->getJson('/api/v1/products?category=Dermokozmetik')->assertOk();
    $this->getJson('/api/v1/products?brand=Bir%20Marka')->assertOk();

    /*
     * THE BUY BOX, added 2026-08-04 as the FOURTH occurrence. `/products/{slug}/offers`
     * is the URL the storefront's product page actually calls, and it went on
     * 500ing after the rest of ADR-059 shipped because Offer resolves the segment
     * through `CatalogBrowseContract` rather than through Catalog's own guard —
     * a path this file did not yet cover. It does now.
     */
    $this->getJson('/api/v1/products/'.$product->slug.'/offers')->assertOk();
    $this->getJson('/api/v1/products/'.$product->uuid.'/offers')->assertOk();
    $this->getJson('/api/v1/products/kesinlikle-boyle-bir-urun-yok/offers')->assertNotFound();

    // And a miss is a 404 or an empty list — never a 500.
    $this->getJson('/api/v1/products/kesinlikle-boyle-bir-urun-yok')->assertNotFound();
    $this->getJson('/api/v1/resolve/kesinlikle-boyle-bir-slug-yok')->assertNotFound();
    $this->getJson('/api/v1/categories/kesinlikle-yok')->assertNotFound();
    $this->getJson('/api/v1/brands/kesinlikle-yok')->assertNotFound();
});

it('keeps a non-uuid checkout group away from the payments uuid column', function (): void {
    /*
     * THE FIFTH WATCH (Payment.md §3, 2026-08-04). `payments.checkout_group_uuid`
     * is a NATIVE uuid column here, so `where('checkout_group_uuid', 'sepet')` is
     * SQLSTATE[22P02] — a 500 on the pay button — while on SQLite it is text and
     * quietly returns false. Four modules have shipped that bug; Payment resolves
     * by shape before it queries, and this is where that is verified on the engine
     * that actually enforces types.
     */
    expect(static fn (): mixed => DB::connection('pgsql')
        ->table('payments')
        ->where('uuid', (string) Str::uuid())
        ->first())
        ->not->toThrow(\Throwable::class);

    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer');

    foreach (['not-a-uuid', 'sepet'] as $group) {
        $this->postJson("/api/v1/checkout/{$group}/pay")->assertNotFound();
    }
});

it('keeps a non-uuid payment away from the refund endpoint', function (): void {
    /*
     * THE SEVENTH WATCH (Payment.md §8, P5). The refund route takes a payment
     * uuid straight off the URL and the ONE endpoint on this platform that moves
     * real money out is the last place a malformed segment should become a 500.
     * `payments.uuid` and `payment_refunds.order_uuid` are native uuid columns
     * here; on SQLite both are text and the guard cannot be seen to work.
     */
    // NO ROLE IS GRANTED, and none is needed: every path here is refused at the
    // SHAPE check, which runs before the policy. That is the property being
    // tested — a malformed segment must never reach the database at all.
    $this->actingAs(Admin::factory()->create(), 'admin');

    foreach (['not-a-uuid', 'odeme'] as $payment) {
        $this->postJson("/api/v1/admin/payments/{$payment}/refund")->assertNotFound();
        $this->getJson("/api/v1/admin/payments/{$payment}/refunds")->assertNotFound();
    }

    // A well-formed uuid that does not exist is also a miss, not an error — and
    // the refunds table takes the same shape check on the way in.
    $this->postJson('/api/v1/admin/payments/'.Str::uuid().'/refund')->assertNotFound();

    expect(static fn (): mixed => DB::connection('pgsql')
        ->table('payment_refunds')
        ->where('order_uuid', (string) Str::uuid())
        ->first())
        ->not->toThrow(\Throwable::class);
});

it('keeps a non-uuid order away from the buyer return endpoint', function (): void {
    /*
     * THE ELEVENTH WATCH (Payment.md §8, S4). The return routes take an ORDER
     * uuid straight off the URL, and the chain behind them touches three native
     * uuid columns on this engine — `settlement_windows.order_uuid`,
     * `payment_refunds.order_uuid` and now `payment_refund_lines.order_line_uuid`.
     * On SQLite all three are text and the guard cannot be seen to work.
     *
     * IT IS A CUSTOMER-FACING SURFACE, which is what raises the stakes over the
     * admin refund route beside it: this is a button in "Siparişlerim", tapped by
     * people who did not type the uuid themselves.
     */
    $this->actingAs(Customer::factory()->create(), 'customer');

    foreach (['not-a-uuid', 'siparis'] as $order) {
        $this->getJson("/api/v1/orders/{$order}/return")->assertNotFound();

        /*
        | **THE WRITE MOVED TO ORDER (ADR-073)** and the guard had to move with
        | it. `POST /orders/{order}/return` refunded on request and is deleted;
        | the buyer now writes a REQUEST, and `return_requests.order_uuid` is one
        | more native uuid column a slug must never reach.
        */
        $this->postJson("/api/v1/orders/{$order}/return-request", [
            'lines' => [['id' => (string) Str::uuid(), 'quantity' => 1]],
        ])->assertNotFound();

        $this->getJson("/api/v1/orders/{$order}/return-request")->assertNotFound();
    }

    // A well-formed uuid nobody owns is a miss, not an error — the same answer,
    // so nothing distinguishes "does not exist" from "not yours".
    $this->getJson('/api/v1/orders/'.Str::uuid()->toString().'/return')->assertNotFound();
    $this->getJson('/api/v1/orders/'.Str::uuid()->toString().'/return-request')->assertNotFound();

    /*
     * AND THE S4 TABLE ITSELF, on the engine that enforces the type. The refunded
     * quantity is summed by `order_line_uuid` on every return — that read has to
     * be safe with a well-formed uuid and the column has to actually be one.
     */
    expect(static fn (): mixed => DB::connection('pgsql')
        ->table('payment_refund_lines')
        ->where('order_line_uuid', (string) Str::uuid())
        ->sum('quantity'))
        ->not->toThrow(\Throwable::class);
});

it('lets one order be refunded twice, which the dropped indexes forbade', function (): void {
    /*
     * THE MIGRATION, VERIFIED ON THE ENGINE THAT HELD THE CONSTRAINT. S4 dropped
     * a UNIQUE on `payment_refunds (payment_id, order_uuid)` and another on
     * `seller_ledger_entries (payment_uuid, order_uuid, type)` so a line-level
     * refund could append a second pair for one order. SQLite would happily
     * accept both rows whether the migration ran or not — only PostgreSQL can
     * say the indexes are genuinely gone.
     */
    $payment = DB::connection('pgsql')->table('payments')->first();

    if ($payment === null) {
        $this->markTestSkipped('No payment exists on this database to append against.');
    }

    $orderUuid = (string) Str::uuid();

    $insert = static fn (): int => (int) DB::connection('pgsql')->table('payment_refunds')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'payment_id' => $payment->id,
        'payment_uuid' => $payment->uuid,
        'order_uuid' => $orderUuid,
        'seller_org_uuid' => (string) Str::uuid(),
        'amount_minor' => 1_200,
        'currency_id' => $payment->currency_id,
        'created_at' => now(),
    ]);

    // One shoe today, the other next week. Two rows, one order — and the
    // transaction rolls the whole thing back afterwards.
    expect($insert())->toBeGreaterThan(0)
        ->and($insert())->toBeGreaterThan(0);
});

it('keeps a non-uuid order away from the cancellation port', function (): void {
    /*
     * THE TWELFTH WATCH (ADR-065, C1). The cancellation port takes an order uuid
     * straight off a panel record and asks Shipping whether the parcel has left —
     * `shipments.order_uuid` is a native uuid column on this engine, so a
     * malformed value would be SQLSTATE[22P02] rather than a miss. On SQLite it is
     * text and the shape guard cannot be seen to work.
     *
     * BOTH HALVES OF THE PORT, because they have separate guards: the command
     * refuses and the read answers empty, and neither may reach the database with
     * something the column cannot hold.
     */
    $port = app(OrderCancellationContract::class);
    $seller = (string) Str::uuid();

    foreach (['not-a-uuid', 'siparis'] as $order) {
        expect($port->cancellableQuantities($order, $seller))->toBe([]);

        expect(function () use ($port, $order, $seller): void {
            $port->cancelLinesBySeller($order, $seller, ['x' => 1]);
        })->toThrow(PaymentException::class);
    }

    // A well-formed uuid nobody sold is the same refusal, and it is allowed to
    // reach the query — which is what proves the column takes it.
    expect(function () use ($port, $seller): void {
        $port->cancelLinesBySeller((string) Str::uuid(), $seller, []);
    })->toThrow(PaymentException::class);

    expect(static fn (): mixed => DB::connection('pgsql')
        ->table('shipments')
        ->where('order_uuid', (string) Str::uuid())
        ->value('status'))
        ->not->toThrow(\Throwable::class);
});

it('stores a cancelled shipment on the engine that has the enum column', function (): void {
    /*
     * THE MIGRATION, ON THE DATABASE THAT ENFORCES THE COLUMN. `cancelled` is a
     * new `ShipmentStatus` value and `cancelled_at` a new column; SQLite would
     * accept both whether the migration ran or not.
     */
    $shipment = DB::connection('pgsql')->table('shipments')->first();

    if ($shipment === null) {
        $this->markTestSkipped('No shipment exists on this database to move.');
    }

    DB::connection('pgsql')->table('shipments')->where('id', $shipment->id)->update([
        'status' => ShipmentStatus::Cancelled->value,
        'cancelled_at' => now(),
    ]);

    $row = DB::connection('pgsql')->table('shipments')->where('id', $shipment->id)->first();

    expect($row?->status)->toBe(ShipmentStatus::Cancelled->value)
        ->and($row?->cancelled_at)->not->toBeNull();
});

it('holds "one open cancellation request per order" at the database', function (): void {
    /*
     * **THE HALF OF THE RULE THE REST OF THE SUITE CANNOT SEE.** The partial
     * unique index is created on PostgreSQL only — the same choice
     * `customer_addresses`' default-address indexes made — so on SQLite the
     * guarantee is the action's check alone, and a double-click racing two
     * requests through it would go unnoticed. Here the database refuses.
     *
     * AND IT IS PARTIAL ON PURPOSE: a REJECTED request must not block asking
     * again, because circumstances change while an item still has not shipped.
     * Both halves are asserted, because an unconditional unique would pass the
     * first and fail the second.
     */
    $orderUuid = (string) Str::uuid();

    $insert = static fn (string $status): int => (int) DB::connection('pgsql')
        ->table('cancellation_requests')
        ->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_uuid' => $orderUuid,
            'requested_by' => 1,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    expect($insert('pending'))->toBeGreaterThan(0);

    /*
     * A SECOND OPEN REQUEST IS REFUSED BY THE INDEX, not by luck.
     *
     * WRAPPED IN A NESTED TRANSACTION so the failure rolls back to a SAVEPOINT
     * rather than poisoning the outer one — PostgreSQL aborts a transaction on
     * any error, and every statement after it would fail for the wrong reason.
     * `QueryException` rather than `Throwable`, because Pest reads a non-class
     * string as a MESSAGE substring and `Throwable` is an interface: the first
     * version of this assertion passed the index's real violation through as a
     * failure.
     */
    expect(static function () use ($insert): void {
        DB::connection('pgsql')->transaction(static fn (): int => $insert('pending'));
    })->toThrow(QueryException::class);

    // A rejected one is fine — asking again is allowed, and must stay allowed.
    expect($insert('rejected'))->toBeGreaterThan(0)
        ->and($insert('rejected'))->toBeGreaterThan(0);

    /*
    | BELT AND BRACES. The `beforeEach` transaction now unwinds everything this
    | file writes, so this delete is no longer what keeps the real database clean —
    | it is what keeps it clean if somebody ever commits mid-test. It was the only
    | mechanism until 2026-08-10, and by then 101 orphaned rows had accumulated at
    | three per suite run.
    */
    DB::connection('pgsql')->table('cancellation_requests')->where('order_uuid', $orderUuid)->delete();
});

it('holds "one open return per order" at the database, counting TWO states', function (): void {
    /*
     * **THE INDEX THAT IS NOT A COPY OF THE CANCELLATION'S.** That one keys on
     * `pending` alone, because a cancellation is over the moment the seller
     * answers. A return is not: an APPROVED return is a buyer walking to the
     * cargo desk with the goods still in hand and the money still unmoved, and a
     * second request for that order while it is in flight is a mistake rather
     * than a new intention.
     *
     * So this asserts the difference explicitly — `approved` must block, and an
     * index copied from `cancellation_requests_one_open` would let it through.
     */
    $orderUuid = (string) Str::uuid();

    $insert = static fn (string $status): int => (int) DB::connection('pgsql')
        ->table('return_requests')
        ->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_uuid' => $orderUuid,
            'requested_by' => 1,
            'customer_id' => 1,
            'status' => $status,
            'line_quantities' => json_encode(['line-a' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    expect($insert('requested'))->toBeGreaterThan(0);

    // NESTED TRANSACTION so the violation rolls back to a SAVEPOINT rather than
    // poisoning the outer one, and `QueryException` rather than `Throwable` —
    // both traps this file has already met once.
    expect(static function () use ($insert): void {
        DB::connection('pgsql')->transaction(static fn (): int => $insert('approved'));
    })->toThrow(QueryException::class);

    /*
     * A COMPLETED OR REJECTED ONE DOES NOT BLOCK ASKING AGAIN, and that is not
     * generosity — S4 made a second refund of one order legitimate (one shoe
     * today, the other next week), so a return that finished must leave the door
     * open behind it.
     */
    DB::connection('pgsql')->table('return_requests')->where('order_uuid', $orderUuid)->delete();

    expect($insert('completed'))->toBeGreaterThan(0)
        ->and($insert('rejected'))->toBeGreaterThan(0)
        ->and($insert('requested'))->toBeGreaterThan(0);

    // Belt and braces, for the reason the cancellation test above states.
    DB::connection('pgsql')->table('return_requests')->where('order_uuid', $orderUuid)->delete();
});

it('lets reset-commerce truncate its whole list in one statement, without CASCADE', function (): void {
    /*
     * **THE INVARIANT THE COMMAND RESTS ON, CHECKED ON THE ENGINE THAT ENFORCES
     * IT.** `marketplace:reset-commerce` empties every table in one multi-table
     * `TRUNCATE`. PostgreSQL permits that only when every table referencing any
     * table in the group is ALSO in the group — which is why the command needs
     * neither `CASCADE` nor the superuser-only `session_replication_role` that
     * failed on the server.
     *
     * If somebody later adds a foreign key from a KEPT table into a deleted one,
     * this fails HERE, in a test, naming the pair. Without it the discovery would
     * be a production run that aborts halfway — or, had `CASCADE` been used to
     * make the error go away, a production run that quietly empties the kept table
     * along with it.
     */
    $delete = ResetCommerceCommand::DELETE;

    $offenders = collect(DB::connection('pgsql')->select("
        select tc.table_name as child, ccu.table_name as parent
        from information_schema.table_constraints tc
        join information_schema.constraint_column_usage ccu on tc.constraint_name = ccu.constraint_name
        where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public'
    "))
        ->filter(fn (object $row): bool => in_array($row->parent, $delete, true)
            && ! in_array($row->child, $delete, true))
        ->map(fn (object $row): string => "{$row->child} -> {$row->parent}")
        ->unique()
        ->values()
        ->all();

    expect($offenders)->toBe(
        [],
        'A kept table now points into a table reset-commerce truncates. Add it to the '
        .'DELETE list or the command will abort — and never "fix" this with CASCADE, '
        .'which would empty the kept table instead.',
    );
});

it('folds a Turkish İ the way the CATALOGUE COLUMN does, not the way PHP does', function (): void {
    /*
     * **THE HALF SQLITE CANNOT ANSWER.** `LOWER()` there is ASCII-only, so it
     * cannot fold `İ` at all and a case-insensitive claim would pass without being
     * tested. PostgreSQL folds it for real — and the disagreement between its
     * folding and PHP's is what put a thousand copies of one category into a live
     * catalogue (ADR-074, fixed 2026-08-10).
     *
     * The importer now folds BOTH sides in SQL. This asserts the property that
     * makes that correct: the database's own `lower()` maps the dotted capital onto
     * the plain lowercase, while PHP's does not.
     */
    $name = 'Yetişkinler İçin Güneş Kremleri';

    $sql = DB::connection('pgsql')->selectOne('select lower(?) as folded', [$name])->folded;

    expect($sql)->toBe('yetişkinler için güneş kremleri')
        // AND THE TRAP, STATED: PHP disagrees, which is why nothing may fold in PHP.
        ->and(mb_strtolower($name))->not->toBe($sql);

    // The comparison the importer actually makes, on the engine that will make it.
    $matches = DB::connection('pgsql')
        ->selectOne('select (lower(?) = lower(?)) as same', [$name, mb_strtoupper($name, 'UTF-8')])
        ->same;

    expect((bool) $matches)->toBeTrue();
});

it('resolves a real slug to its type on PostgreSQL', function (): void {
    $row = DB::connection('pgsql')->table('slugs')->where('is_canonical', true)->first();

    if ($row === null) {
        $this->markTestSkipped('The slug registry is not backfilled on this database.');
    }

    // The registry itself, on the engine that enforces types — the whole chain
    // from a URL segment through `slugs` to a uuid.
    $match = app(SlugRegistryContract::class)->resolve((string) $row->slug);

    expect($match)->not->toBeNull()
        ->and($match->slug)->toBe((string) $row->slug)
        ->and($match->uuid)->not->toBe('');
});
