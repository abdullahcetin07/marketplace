<?php

declare(strict_types=1);

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
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
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
