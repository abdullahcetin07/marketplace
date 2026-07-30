<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\PauseOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferPriceAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Order\Application\Actions\AddCartItemAction;
use App\Modules\Order\Application\Actions\RemoveCartItemAction;
use App\Modules\Order\Application\Actions\UpdateCartItemAction;
use App\Modules\Order\Application\Services\CartPricingService;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\DTOs\UpdateCartItemDTO;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Cart;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| The basket (§2.1) — and the one rule it exists to keep
|--------------------------------------------------------------------------
|
| A CART STORES NO PRICES. Every amount is read live from the Offer, so a seller
| who re-prices changes what the basket says. That is correct for a basket and
| exactly wrong for an order (ADR-053) — the two rules living apart IS the
| boundary, and most of this file is about proving the cart side of it.
|
| The tests drive the REAL Offer actions rather than fixtures wherever the answer
| depends on offer state: the cart validates through `OfferQueryContract`, and a
| fixture that wrote an offer row directly would not exercise the eligibility rule
| the buy box applies.
|
| ADDING RESERVES NOTHING, deliberately. A cart is not a claim; browsing must not
| consume a seller's stock. The arbitration is at checkout (ADR-054), and a
| customer may still lose the race in between — honest behaviour, not a bug.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A real, sellable offer — everything `CreateOfferAction` validates, so the
 * contract reads under test see production-shaped data.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{offer: \App\Modules\Offer\Domain\Models\Offer, org: Organization, store: Store, variant: ProductVariant, product: Product}
 */
function sellableOffer(int $priceMinor = 12_990, int $stock = 10, ?string $title = null): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => $title ?? 'Pamuklu Tişört']);
    $variant = ProductVariant::factory()->for($product)->create();

    $offer = app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: $priceMinor,
        stockQuantity: $stock,
    ));

    return [
        'offer' => $offer,
        'org' => $organization,
        'store' => $store,
        'variant' => $variant,
        'product' => $product,
    ];
}

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it('adds a line carrying the offer’s identity but not its price', function (): void {
    $fixture = sellableOffer();

    $item = app(AddCartItemAction::class)->run(1, 'musteri-uuid', new AddCartItemDTO(
        offerUuid: $fixture['offer']->uuid,
        quantity: 2,
    ));

    /*
     * The four denormalized uuids come from the OFFER, never from the client: a
     * payload that could name its own variant or seller could put a cheap offer's
     * uuid on an expensive product's line.
     */
    expect($item->variant_uuid)->toBe($fixture['variant']->uuid)
        ->and($item->product_uuid)->toBe($fixture['product']->uuid)
        ->and($item->selling_org_uuid)->toBe($fixture['org']->uuid)
        ->and($item->store_uuid)->toBe($fixture['store']->uuid)
        ->and($item->quantity)->toBe(2)
        // AND NO PRICE — the column does not exist, and this is the assertion
        // that says so out loud.
        ->and($item->getAttributes())->not->toHaveKey('unit_price_minor');
});

it('creates the basket on the first add and reuses it after', function (): void {
    $first = sellableOffer();
    $second = sellableOffer();

    app(AddCartItemAction::class)->run(1, 'musteri-uuid', new AddCartItemDTO($first['offer']->uuid));
    app(AddCartItemAction::class)->run(1, 'musteri-uuid', new AddCartItemDTO($second['offer']->uuid));

    // One basket per customer (§2.1) — the schema enforces it, and this proves the
    // action does not fight the schema.
    expect(Cart::query()->forCustomer(1)->count())->toBe(1)
        ->and(Cart::query()->forCustomer(1)->sole()->items()->count())->toBe(2);
});

it('raises the quantity when the same offer is added again', function (): void {
    $fixture = sellableOffer();

    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 2));
    $item = app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 3));

    // Two lines for one thing is a basket a customer cannot reason about, and a
    // checkout that would reserve twice against one pool.
    expect($item->quantity)->toBe(5)
        ->and(Cart::query()->forCustomer(1)->sole()->items()->count())->toBe(1);
});

it('refuses an offer that is not sellable right now', function (): void {
    $fixture = sellableOffer();
    app(PauseOfferAction::class)->run($fixture['offer'], 'Stok bekleniyor');

    /*
     * One refusal for paused, suspended, closed-shop and sold-out alike: from the
     * buyer's side they are the same fact, and enumerating a seller's internal
     * state to a shopper leaks how the platform works without helping them.
     */
    expect(fn () => app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid)))
        ->toThrow(OrderException::class);
});

it('refuses an offer uuid that does not exist', function (): void {
    expect(fn () => app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO('yok-boyle-bir-teklif')))
        ->toThrow(OrderException::class);
});

it('refuses a quantity outside the guard rails', function (): void {
    $fixture = sellableOffer();
    $max = (int) config('order.cart.max_quantity_per_line');

    // Not an availability check — Inventory owns that. This catches a
    // fat-fingered 1000 and an attempt to hold a seller's whole stock for free.
    expect(fn () => app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 0)))
        ->toThrow(OrderException::class)
        ->and(fn () => app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, $max + 1)))
        ->toThrow(OrderException::class);
});

it('reserves nothing — a basket is not a claim', function (): void {
    $fixture = sellableOffer(stock: 1);

    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid));

    /*
     * THE SINGLE MOST IMPORTANT NEGATIVE IN THIS FILE. If adding to a basket held
     * stock, a platform where people browse would sell nothing — and the last unit
     * would belong to whoever clicked first rather than whoever paid first. The
     * arbitration is at checkout (ADR-054).
     */
    expect(app(\App\Core\Domain\Contracts\InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(1);

    // And a second shopper can still add the same last unit.
    app(AddCartItemAction::class)->run(2, 'n', new AddCartItemDTO($fixture['offer']->uuid));

    expect(Cart::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Changing and removing
|--------------------------------------------------------------------------
*/

it('sets an absolute quantity, never a delta', function (): void {
    $fixture = sellableOffer();
    $item = app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 2));

    app(UpdateCartItemAction::class)->run($item, new UpdateCartItemDTO(quantity: 5));

    // A delta over an unreliable network is how a double-tapped `+` becomes five
    // items; the client already knows the number it means to show.
    expect($item->fresh()->quantity)->toBe(5);
});

it('does not treat quantity zero as a delete', function (): void {
    $fixture = sellableOffer();
    $item = app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid));

    // Overloading zero would make "set it to what the box says" silently
    // destructive when a customer clears the field to retype it.
    expect(fn () => app(UpdateCartItemAction::class)->run($item, new UpdateCartItemDTO(quantity: 0)))
        ->toThrow(OrderException::class)
        ->and($item->fresh())->not->toBeNull();
});

it('lets a customer change a line whose offer has since been paused', function (): void {
    $fixture = sellableOffer();
    $item = app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 3));

    app(PauseOfferAction::class)->run($fixture['offer'], 'Stok bekleniyor');

    /*
     * A basket that started rejecting edits to lines it already contains is a
     * basket a customer cannot empty. The refusal belongs at checkout, where the
     * whole basket is validated at once and they can act on all of it together.
     */
    app(UpdateCartItemAction::class)->run($item, new UpdateCartItemDTO(quantity: 1));
    expect($item->fresh()->quantity)->toBe(1);

    app(RemoveCartItemAction::class)->run($item);
    expect(Cart::query()->forCustomer(1)->sole()->items()->count())->toBe(0);
});

it('keeps the basket when its last line is removed', function (): void {
    $fixture = sellableOffer();
    $item = app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid));

    app(RemoveCartItemAction::class)->run($item);

    // The customer will shop again; a row per purchase is churn.
    expect(Cart::query()->forCustomer(1)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Live pricing (§2.1)
|--------------------------------------------------------------------------
*/

it('follows the seller’s price change — the whole reason no price is stored', function (): void {
    $fixture = sellableOffer(priceMinor: 12_990);
    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid, 2));

    $cart = app(CartRepositoryContract::class)->forCustomer(1);
    expect(app(CartPricingService::class)->price($cart)['items_total_minor'])->toBe(25_980);

    // The seller re-prices at 14:00; the basket says so at 14:01.
    app(UpdateOfferPriceAction::class)->run($fixture['offer'], new UpdateOfferPriceDTO(priceMinor: 9_990));

    $cart = app(CartRepositoryContract::class)->forCustomer(1);
    expect(app(CartPricingService::class)->price($cart)['items_total_minor'])->toBe(19_980);
});

it('shows a paused line as unavailable instead of hiding it', function (): void {
    $fixture = sellableOffer();
    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid));

    app(PauseOfferAction::class)->run($fixture['offer'], 'Stok bekleniyor');

    $priced = app(CartPricingService::class)->price(app(CartRepositoryContract::class)->forCustomer(1));

    /*
     * Silently dropping the line would leave a customer wondering what happened to
     * the thing they chose. And the price is NULL rather than a last-known one —
     * the cart never stored a price, and inventing one here would be the stale
     * copy §2.1 exists to avoid.
     */
    expect($priced['has_unsellable_lines'])->toBeTrue()
        ->and($priced['lines'][0]['sellable'])->toBeFalse()
        ->and($priced['lines'][0]['unit_price_minor'])->toBeNull()
        ->and($priced['items_total_minor'])->toBe(0);
});

it('groups the basket by the sellers it will split into', function (): void {
    $first = sellableOffer(priceMinor: 10_000, title: 'Tişört');
    $second = sellableOffer(priceMinor: 5_000, title: 'Kupa');

    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($first['offer']->uuid, 2));
    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($second['offer']->uuid, 1));

    $priced = app(CartPricingService::class)->price(app(CartRepositoryContract::class)->forCustomer(1));

    /*
     * "This will arrive as 2 separate deliveries" is something a shopper should
     * learn while they can still change their mind (ADR-052) — so the split is
     * visible before checkout, not on the confirmation screen.
     */
    expect($priced['groups'])->toHaveCount(2)
        ->and($priced['items_total_minor'])->toBe(25_000);

    $byOrg = collect($priced['groups'])->keyBy('selling_org_uuid');

    expect($byOrg[$first['org']->uuid]['items_total_minor'])->toBe(20_000)
        ->and($byOrg[$second['org']->uuid]['items_total_minor'])->toBe(5_000);
});

it('carries the catalog’s title without the cart storing one', function (): void {
    $fixture = sellableOffer(title: 'Pamuklu Tişört');
    app(AddCartItemAction::class)->run(1, 'm', new AddCartItemDTO($fixture['offer']->uuid));

    $priced = app(CartPricingService::class)->price(app(CartRepositoryContract::class)->forCustomer(1));

    // Read through `CatalogBrowseContract` per render, in one batched call — the
    // title is the catalog's, and a copy on the line would be the stale one.
    expect($priced['lines'][0]['title'])->toBe('Pamuklu Tişört');
});

it('prices an empty basket as zero rather than failing', function (): void {
    $cart = Cart::factory()->forCustomer(1, 'm')->create();

    $priced = app(CartPricingService::class)->price($cart->load('items'));

    expect($priced['items_total_minor'])->toBe(0)
        ->and($priced['lines'])->toBe([])
        ->and($priced['groups'])->toBe([])
        ->and($priced['has_unsellable_lines'])->toBeFalse();
});
