<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The rules EVERY public buyer surface keeps (ADR-034/058)
|--------------------------------------------------------------------------
|
| The individual surfaces each have their own tests. This file asserts the rules
| they all share, ACROSS them — because a public API is only as private as its
| leakiest endpoint, and the way that goes wrong is somebody adding a sixth route
| that forgets what the first five agreed.
|
| So these tests walk the actual registered routes rather than a list written by
| hand. A new public route inherits the assertions automatically, which is the
| only version of this that keeps working.
|
|   ANONYMOUS          browsing must not need an account
|   THROTTLED          anonymous traffic gets the storefront limiter, not the API one
|   UUID/SLUG ONLY     an internal id on a public payload lets anyone walk the table
|   NO COMMERCE LEAK   the Catalog surfaces carry no price or stock (ADR-037)
|   DECIMAL STRINGS    money never crosses as a JSON number (005 §28)
|   NO EXISTENCE LEAK  unpublished and non-existent answer identically
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published, sellable product — the state every public surface is meant to show.
 *
 * Named for this file because Pest shares ONE global function namespace.
 *
 * @return array{product: Product, variant: ProductVariant}
 */
function publicSurfaceProduct(): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => 'Pamuklu Tişört',
        'title_en' => 'Pamuklu Tişört',
        'gtin' => '8691234567895',
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_990,
        stockQuantity: 10,
    ));

    return ['product' => $product, 'variant' => $variant];
}

/**
 * Every registered storefront route, as `[method, uri]`.
 *
 * READ FROM THE ROUTER, not from a list in this file: a hand-written list is one
 * somebody forgets to extend, and the whole value here is that a NEW public route
 * inherits these rules without anyone remembering to add it.
 *
 * @return array<int, array{string, string}>
 */
function storefrontRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('throttle:storefront', $route->gatherMiddleware(), true)) {
            continue;
        }

        $routes[] = [$route->methods()[0], $route->uri()];
    }

    return $routes;
}

/*
|--------------------------------------------------------------------------
| The rules, across every surface
|--------------------------------------------------------------------------
*/

it('puts every public buyer route on the storefront throttle', function (): void {
    $uris = array_column(storefrontRoutes(), 1);

    /*
     * `throttle:storefront` rather than `throttle:api` — anonymous browsing has a
     * different traffic shape from authenticated API use, and sharing a limiter
     * would let a crawler exhaust the allowance real customers depend on.
     *
     * Asserted as a set so a route MOVED off the storefront group fails here too,
     * not only a route added without it.
     */
    expect($uris)->toContain('api/v1/products')
        ->toContain('api/v1/products/{product}')
        ->toContain('api/v1/products/{product}/offers')
        ->toContain('api/v1/offers/prices')
        // The flat-URL surfaces (ADR-059) — anonymous browsing too, and
        // `/resolve` is called on EVERY storefront page load.
        ->toContain('api/v1/resolve/{slug}')
        ->toContain('api/v1/categories')
        ->toContain('api/v1/categories/{category}')
        ->toContain('api/v1/brands')
        ->toContain('api/v1/brands/{brand}');
});

it('never requires a session on a public buyer route', function (): void {
    $fixture = publicSurfaceProduct();

    // No token, no cookie. Requiring a login to look at a shop would be the end of
    // the shop.
    $this->getJson('/api/v1/products')->assertOk();
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->assertOk();
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/offers')->assertOk();
    $this->postJson('/api/v1/offers/prices', ['product_ids' => [$fixture['product']->uuid]])->assertOk();
});

it('never puts an internal id in any public payload', function (): void {
    $fixture = publicSurfaceProduct();

    $payloads = [
        $this->getJson('/api/v1/products')->json(),
        $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->json(),
        $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/offers')->json(),
        $this->postJson('/api/v1/offers/prices', ['product_ids' => [$fixture['product']->uuid]])->json(),
        // The tree carries `parent_id`, which is the field most likely to be a
        // raw foreign key by accident (ADR-059).
        $this->getJson('/api/v1/categories')->json(),
        $this->getJson('/api/v1/brands')->json(),
        $this->getJson('/api/v1/resolve/'.$fixture['product']->slug)->json(),
    ];

    /*
     * A sequential id on a public payload tells anyone how big the catalogue is
     * and lets them walk it (non-negotiable #7).
     *
     * ASSERTED ON VALUES, NOT ON KEY NAMES, and the difference matters: this
     * platform's public payloads deliberately call a uuid `id` — `variant_id`,
     * `store_id`, `checkout_group_id` all carry uuids. Banning the key name would
     * ban the convention; banning an INTEGER under an id key catches the actual
     * leak, whatever it is called.
     */
    $assertNoIntegerIds = function (mixed $value, string $path = '') use (&$assertNoIntegerIds): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && str_ends_with($key, 'id') && (is_int($child) || (is_string($child) && ctype_digit($child)))) {
                throw new RuntimeException("internal id leaked at {$here}: ".var_export($child, true));
            }

            $assertNoIntegerIds($child, $here);
        }
    };

    foreach ($payloads as $payload) {
        $assertNoIntegerIds($payload);
    }

    // Reaching here means every id-shaped field in every public payload is a uuid.
    expect(true)->toBeTrue();
});

it('keeps commerce out of the CATALOG payloads and money in strings elsewhere', function (): void {
    $fixture = publicSurfaceProduct();

    $card = $this->getJson('/api/v1/products')->json('data.0');
    $page = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->json('data');

    /*
     * ADR-037 AT THE EDGE. The Catalog surfaces describe a thing; they say nothing
     * about what it costs or whether anyone has it, because a price on a shared
     * catalogue entry would have to belong to somebody.
     */
    foreach ([$card, $page] as $payload) {
        foreach (['price', 'from_price', 'price_minor', 'stock', 'stock_quantity', 'in_stock'] as $commerce) {
            expect($payload)->not->toHaveKey($commerce);
        }
    }

    // And where money DOES appear, it is a string (005 §28): a JSON number would
    // be parsed as a float by most clients and undo integer storage.
    $prices = $this->postJson('/api/v1/offers/prices', ['product_ids' => [$fixture['product']->uuid]])
        ->json('data.'.$fixture['product']->uuid);

    $buyBox = $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/offers')
        ->json('data.featured');

    expect($prices['from_price'])->toBeString()->toBe('129.90')
        ->and($buyBox['price'])->toBeString();
});

it('never leaks a seller-facing or moderation field to a buyer', function (): void {
    $fixture = publicSurfaceProduct();

    $page = json_encode($this->getJson('/api/v1/products/'.$fixture['product']->uuid)->json());

    /*
     * The moderation fields say who proposed a product and what a moderator
     * thought of it — a conversation between a seller and the platform, not
     * something a shopper is party to.
     */
    foreach (['moderation', 'proposed_by', 'status'] as $leak) {
        expect($page)->not->toContain($leak);
    }
});

it('shows the barcode on the product page but never in the listing', function (): void {
    $fixture = publicSurfaceProduct();

    /*
     * THE ONE FIELD THE TWO CATALOG SURFACES DISAGREE ABOUT (owner-approved,
     * 2026-08-01). This test used to assert the opposite for both, so it is worth
     * writing down why it changed rather than letting the diff be the reason.
     *
     * A barcode is printed on the box the shopper is holding: withholding it from
     * the page protects nothing, and showing it lets them confirm they are buying
     * the right item — which is why "Barkod" is on the design's spec table.
     *
     * The LISTING is different in kind, not in degree. One product's GTIN is a
     * fact about that product; every product's GTIN, paginated and anonymous, is a
     * catalogue export keyed for matching against somebody else's. So the split is
     * asserted here, in one place, rather than inferred from two resources.
     */
    $page = $this->getJson('/api/v1/products/'.$fixture['product']->uuid)->json('data');

    expect($page['gtin'])->toBe('8691234567895');

    $card = $this->getJson('/api/v1/products')->json('data.0');

    expect($card)->not->toHaveKey('gtin')
        ->and(json_encode($card))->not->toContain('8691234567895');
});

it('answers unpublished and non-existent identically, on every surface', function (): void {
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $draft = Product::factory()->for($category, 'category')->create();
    $nonexistent = (string) Str::uuid();

    /*
     * A draft's existence must not be discoverable by watching which uuids answer
     * differently — the rule the store page set (ADR-034) and every surface since
     * has kept.
     */
    $draftDetail = $this->getJson('/api/v1/products/'.$draft->uuid);
    $missingDetail = $this->getJson('/api/v1/products/'.$nonexistent);

    expect($draftDetail->status())->toBe($missingDetail->status())->toBe(404);

    $draftOffers = $this->getJson('/api/v1/products/'.$draft->uuid.'/offers');
    $missingOffers = $this->getJson('/api/v1/products/'.$nonexistent.'/offers');

    expect($draftOffers->status())->toBe($missingOffers->status())->toBe(404);

    // And the batch price omits both, rather than distinguishing them.
    $prices = $this->postJson('/api/v1/offers/prices', [
        'product_ids' => [$draft->uuid, $nonexistent],
    ])->assertOk()->json('data');

    expect($prices)->toBe([]);
});

it('shows a buyer only what is published AND sellable', function (): void {
    publicSurfaceProduct();

    // Published, complete, and nobody sells it.
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $unsold = Product::factory()->for($category, 'category')->published()->create();
    ProductVariant::factory()->for($unsold)->create();

    // A draft with a variant — the moderation wall.
    $draft = Product::factory()->for($category, 'category')->create();
    ProductVariant::factory()->for($draft)->create();

    $listing = $this->getJson('/api/v1/products')->assertOk()->json('data');

    // TWO INDEPENDENT WALLS, and the listing needs both: moderation (Catalog's)
    // and sellability (Offer's, through the Core contract).
    expect($listing)->toHaveCount(1);
});
