<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The customer API (§4) — the surface with no UI behind it yet
|--------------------------------------------------------------------------
|
| The storefront is a separate Next.js app that does not exist (§0.8), so this
| ships and waits. That makes the tests the ONLY consumer, and therefore the only
| thing that can catch a payload the future client cannot work with — so they
| assert the shape, not merely the status code.
|
| Four properties matter more than the endpoints themselves:
|
|  1. MONEY IS A DECIMAL STRING (005 §28). `..._minor` must never appear: most
|     clients parse a JSON number as a float, undoing integer storage.
|  2. NO INTERNAL IDS (#7). Every identifier out is a uuid.
|  3. ANOTHER CUSTOMER'S ANYTHING IS A 404, NOT A 403 — a 403 confirms the row
|     exists, and an address is where a person lives.
|  4. CHECKOUT AND PLACE ARE TWO CALLS, and both return the WHOLE group (ADR-052).
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A sellable offer.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{offer: \App\Modules\Offer\Domain\Models\Offer, org: Organization, variant: ProductVariant}
 */
function apiOffer(int $priceMinor = 12_000, int $stock = 10, ?string $title = null): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    /*
    | BOTH LOCALES SET, deliberately. `Product::localized('title')` resolves
    | against the ACTIVE locale, and an HTTP request runs through `SetLocale` —
    | so a fixture that filled only `title_tr` would assert against whichever
    | locale the middleware happened to pick, and fail for a reason unrelated to
    | the endpoint under test.
    */
    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title ?? 'Pamuklu Tişört',
        'title_en' => $title ?? 'Pamuklu Tişört',
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return ['offer' => $offer, 'org' => $organization, 'variant' => $variant];
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function apiAddressPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Ev',
        'recipient_name' => 'Ayşe Yılmaz',
        'phone' => '+905551234567',
        'line1' => 'Bağdat Caddesi 120',
        'district' => 'Kadıköy',
        'city' => 'İstanbul',
        'postal_code' => '34710',
        'country' => 'TR',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| The basket
|--------------------------------------------------------------------------
*/

it('gives a customer with no basket an empty one rather than a 404', function (): void {
    $this->actingAsCustomer();

    // "You have no cart" is not a client error, and a storefront rendering a
    // header badge should not have to branch on it.
    $this->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.order_count', 0);
});

it('adds a line and returns the whole priced basket', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer(priceMinor: 12_000);

    $response = $this->postJson('/api/v1/cart/items', [
        'offer_id' => $fixture['offer']->uuid,
        'quantity' => 2,
    ])->assertCreated();

    /*
     * MONEY AS A DECIMAL STRING, and the minor-unit integer nowhere in sight.
     */
    $response->assertJsonPath('data.items.0.unit_price', '120.00')
        ->assertJsonPath('data.items.0.line_total', '240.00')
        ->assertJsonPath('data.items_total', '240.00')
        ->assertJsonPath('data.items.0.quantity', 2)
        // The catalog's title, carried through without the cart storing one.
        ->assertJsonPath('data.items.0.title', 'Pamuklu Tişört');

    expect(json_encode($response->json()))->not->toContain('_minor');
});

it('tells the client how many orders the basket will become', function (): void {
    $this->actingAsCustomer();

    $first = apiOffer(title: 'Tişört');
    $second = apiOffer(title: 'Kupa');

    $this->postJson('/api/v1/cart/items', ['offer_id' => $first['offer']->uuid]);
    $this->postJson('/api/v1/cart/items', ['offer_id' => $second['offer']->uuid]);

    // "This will arrive as 2 separate deliveries" belongs on the basket screen,
    // while a shopper can still change their mind (ADR-052).
    $this->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.order_count', 2)
        ->assertJsonCount(2, 'data.sellers');
});

it('404s on a basket line that is not the acting customer’s', function (): void {
    $other = Customer::factory()->create();
    $fixture = apiOffer();

    $this->actingAsCustomer($other);
    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid]);
    $theirLine = \App\Modules\Order\Domain\Models\CartItem::query()->sole();

    $this->actingAsCustomer();

    // 404 rather than 403: saying which of the two reasons applies would confirm
    // the line exists.
    $this->patchJson("/api/v1/cart/items/{$theirLine->uuid}", ['quantity' => 5])
        ->assertNotFound();
});

it('refuses a quantity of zero on the way in', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer();

    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid]);
    $line = \App\Modules\Order\Domain\Models\CartItem::query()->sole();

    // Zero is not a delete — overloading it would make "set it to what the box
    // says" silently destructive.
    $this->patchJson("/api/v1/cart/items/{$line->uuid}", ['quantity' => 0])
        ->assertUnprocessable();
});

it('locks the basket to authenticated customers', function (): void {
    // No guest checkout in v1 (ADR-056), so a basket belongs to an account from
    // the first item rather than being migrated at login.
    $this->getJson('/api/v1/cart')->assertUnauthorized();

    $this->actingAsSeller();
    $this->getJson('/api/v1/cart')->assertOk();
    $this->postJson('/api/v1/cart/items', ['offer_id' => (string) Str::uuid()])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The address book
|--------------------------------------------------------------------------
*/

it('creates an address and makes the first one both defaults', function (): void {
    $this->actingAsCustomer();

    $this->postJson('/api/v1/addresses', apiAddressPayload())
        ->assertCreated()
        ->assertJsonPath('data.label', 'Ev')
        ->assertJsonPath('data.country', 'TR')
        ->assertJsonPath('data.is_default_shipping', true)
        ->assertJsonPath('data.is_default_billing', true);
});

it('never returns an internal id for an address', function (): void {
    $this->actingAsCustomer();

    $response = $this->postJson('/api/v1/addresses', apiAddressPayload())->assertCreated();

    // `id` IS the uuid, and `country_id` is absent entirely — the country crosses
    // as the ISO code the client sent.
    expect($response->json('data.id'))->toBeString()->toHaveLength(36)
        ->and($response->json('data'))->not->toHaveKey('country_id')
        ->and($response->json('data'))->not->toHaveKey('customer_id');
});

it('refuses a country that is not in the lookup', function (): void {
    $this->actingAsCustomer();

    $this->postJson('/api/v1/addresses', apiAddressPayload(['country' => 'ZZ']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('country');
});

it('404s on another customer’s address, for every verb', function (): void {
    $other = Customer::factory()->create();
    $this->actingAsCustomer($other);
    $theirs = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $this->actingAsCustomer();

    /*
     * An address is where a person lives. A 403 would confirm the uuid is real,
     * so "not yours" and "does not exist" resolve to the same 404 on every route.
     */
    $this->patchJson("/api/v1/addresses/{$theirs}", apiAddressPayload())->assertNotFound();
    $this->deleteJson("/api/v1/addresses/{$theirs}")->assertNotFound();
    $this->postJson("/api/v1/addresses/{$theirs}/default")->assertNotFound();
});

it('promotes one address for one purpose', function (): void {
    $this->actingAsCustomer();

    $home = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');
    $work = $this->postJson('/api/v1/addresses', apiAddressPayload(['label' => 'İş']))->json('data.id');

    $this->postJson("/api/v1/addresses/{$work}/default", ['shipping' => true, 'billing' => false])
        ->assertOk()
        ->assertJsonPath('data.is_default_shipping', true)
        ->assertJsonPath('data.is_default_billing', false);

    // "Deliver to the office, invoice the home address" (ADR-056).
    $this->getJson('/api/v1/addresses')
        ->assertOk()
        ->assertJsonFragment(['id' => $home, 'is_default_shipping' => false, 'is_default_billing' => true]);
});

/*
|--------------------------------------------------------------------------
| Checkout, place, orders
|--------------------------------------------------------------------------
*/

it('checks out into a group and returns every order in it', function (): void {
    $this->actingAsCustomer();

    $first = apiOffer(priceMinor: 10_000, title: 'Tişört');
    $second = apiOffer(priceMinor: 5_000, title: 'Kupa');

    $this->postJson('/api/v1/cart/items', ['offer_id' => $first['offer']->uuid]);
    $this->postJson('/api/v1/cart/items', ['offer_id' => $second['offer']->uuid]);

    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $response = $this->postJson('/api/v1/checkout', [
        'shipping_address_id' => $address,
        'billing_address_id' => $address,
    ])->assertCreated();

    /*
     * THE WHOLE GROUP, never a single order: a purchase is N orders, and a client
     * that received one would have to discover the rest.
     */
    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', OrderStatus::Pending->value)
        // The handle for the very next call, in the envelope rather than left to
        // be read off the first row.
        ->assertJsonPath('meta.checkout_group_id', $response->json('data.0.checkout_group_id'));

    expect(json_encode($response->json()))->not->toContain('_minor');
});

it('places the whole group and moves it to awaiting payment', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer();

    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid]);
    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $group = $this->postJson('/api/v1/checkout', [
        'shipping_address_id' => $address,
        'billing_address_id' => $address,
    ])->json('meta.checkout_group_id');

    $this->postJson("/api/v1/checkout/{$group}/place")
        ->assertOk()
        ->assertJsonPath('data.0.status', OrderStatus::AwaitingPayment->value);
});

it('requires both addresses and infers neither from the other', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer();

    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid]);
    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    /*
     * Inferring "billing = shipping when omitted" would silently put a home
     * address on a company's invoice, and nobody would notice until an accountant
     * did.
     */
    $this->postJson('/api/v1/checkout', ['shipping_address_id' => $address])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('billing_address_id');
});

it('shows an order with its lines, its tax breakdown and its group', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer(priceMinor: 12_000);

    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid, 'quantity' => 2]);
    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $order = $this->postJson('/api/v1/checkout', [
        'shipping_address_id' => $address,
        'billing_address_id' => $address,
    ])->json('data.0');

    $this->getJson("/api/v1/orders/{$order['id']}")
        ->assertOk()
        // 24 000 KDV-INCLUDED at %20 → 4 000 of it is tax (ADR-042/055).
        ->assertJsonPath('data.items_total', '240.00')
        ->assertJsonPath('data.tax_total', '40.00')
        ->assertJsonPath('data.grand_total', '240.00')
        ->assertJsonPath('data.lines.0.tax_rate', '0.2000')
        ->assertJsonPath('data.lines.0.line_tax', '40.00')
        // The handle that reassembles a purchase (ADR-052).
        ->assertJsonPath('data.checkout_group_id', $order['checkout_group_id'])
        // THE SNAPSHOT, not a lookup (ADR-053).
        ->assertJsonPath('data.lines.0.title', 'Pamuklu Tişört')
        ->assertJsonPath('data.shipping_address.city', 'İstanbul');
});

it('carries the shop a customer bought from, named and linkable', function (): void {
    $this->actingAsCustomer();
    $fixture = apiOffer(priceMinor: 12_000);

    /** @var Store $store */
    $store = Store::query()->where('organization_id', $fixture['org']->getKey())->firstOrFail();

    $this->postJson('/api/v1/cart/items', ['offer_id' => $fixture['offer']->uuid, 'quantity' => 1]);
    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $order = $this->postJson('/api/v1/checkout', [
        'shipping_address_id' => $address,
        'billing_address_id' => $address,
    ])->json('data.0');

    /*
     * **THE ORDER CARRIES A STORE UUID AND NOTHING ELSE**, so "Siparişlerim"
     * could show a customer where they bought something only as a uuid. The name
     * and the slug arrive through `StoreQueryContract` — Order imports no module
     * — and the slug is what makes the name a LINK to `/magaza/{slug}`
     * (ADR-035).
     *
     * ASSERTED ON EVERY SHAPE THE RESOURCE COMES OUT OF, because the field is
     * stamped by the controller rather than read off a column: a surface that
     * forgot the batch resolver would silently render `null` forever.
     */
    expect($order['store'])->toBe(['name' => $store->name, 'slug' => $store->slug]);

    $this->getJson("/api/v1/orders/{$order['id']}")
        ->assertOk()
        ->assertJsonPath('data.store.slug', $store->slug);

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.store.name', $store->name)
        ->assertJsonPath('data.0.store.slug', $store->slug);

    $this->postJson("/api/v1/checkout/{$order['checkout_group_id']}/place")
        ->assertOk()
        ->assertJsonPath('data.0.store.slug', $store->slug);

    /*
     * **A SUSPENDED SHOP IS ABSENT, NOT NAMED.** The profile read is live-only,
     * so the whole object goes rather than the slug alone — the storefront shows
     * the order without a link instead of linking somewhere that will not load.
     * `store_id` stays: it identifies the row, it does not invite a click.
     */
    $store->forceFill(['status' => StoreStatus::Suspended])->save();

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonPath('data.0.store', null)
        ->assertJsonPath('data.0.store_id', $store->uuid);
});

it('lists only the acting customer’s orders', function (): void {
    $other = Customer::factory()->create();

    $mine = Order::factory()->forCustomer((int) $this->actingAsCustomer()->getKey(), 'benim')->create();
    $theirs = Order::factory()->forCustomer((int) $other->getKey(), 'onun')->create();

    $this->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->uuid);

    // And reaching for the other one directly is a 404, not a 403.
    $this->getJson("/api/v1/orders/{$theirs->uuid}")->assertNotFound();
});

it('cancels one seller’s order without touching the rest of the purchase', function (): void {
    $this->actingAsCustomer();

    $first = apiOffer(title: 'Tişört');
    $second = apiOffer(title: 'Kupa');

    $this->postJson('/api/v1/cart/items', ['offer_id' => $first['offer']->uuid]);
    $this->postJson('/api/v1/cart/items', ['offer_id' => $second['offer']->uuid]);
    $address = $this->postJson('/api/v1/addresses', apiAddressPayload())->json('data.id');

    $orders = $this->postJson('/api/v1/checkout', [
        'shipping_address_id' => $address,
        'billing_address_id' => $address,
    ])->json('data');

    $this->postJson("/api/v1/orders/{$orders[0]['id']}/cancel", ['reason' => 'Vazgeçtim'])
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

    /*
     * PER ORDER, not per group (ADR-052): a customer who wants one seller's half
     * cancelled should not have to abandon the other's.
     */
    $this->getJson("/api/v1/orders/{$orders[1]['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Pending->value);
});

it('will not let a customer cancel somebody else’s order', function (): void {
    $other = Customer::factory()->create();
    $theirs = Order::factory()->forCustomer((int) $other->getKey(), 'onun')->create();

    $this->actingAsCustomer();

    $this->postJson("/api/v1/orders/{$theirs->uuid}/cancel")->assertNotFound();

    expect($theirs->fresh()->status)->toBe(OrderStatus::Pending);
});
