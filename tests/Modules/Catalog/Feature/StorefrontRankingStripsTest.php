<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| "Çok Satanlar" + "En Çok Değerlendirilenler" (ADR-078)
|--------------------------------------------------------------------------
|
| Both strips are computed on read: Catalog asks Order what sold and Reviews what
| was reviewed, through Core ports, and hydrates the ranked uuids into its own
| cards. Nothing is stored, so nothing can go stale against the orders it is
| derived from.
|
| The tests below care about three things in order of importance: that only a real
| SALE counts, that the rank survives hydration (SQL `IN` is a set), and that a
| card the buyer cannot buy never reaches them.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    // The endpoints cache for an hour; a test that shared it would assert the
    // previous test's answer.
    Cache::flush();
});

/**
 * A published product with a live, in-stock offer behind it.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function strippableProduct(string $title, int $stock = 10): Product
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'title_tr' => $title,
        'title_en' => $title,
    ]);
    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: $stock,
    ));

    return $product;
}

/**
 * One order in one basket, with a line per product.
 *
 * @param array<string, int> $quantities product uuid => quantity
 */
function strippableOrder(array $quantities, OrderStatus $status = OrderStatus::Paid, ?string $group = null): Order
{
    /** @var Order $order */
    $order = Order::factory()->status($status)->create([
        'checkout_group_uuid' => $group ?? (string) Str::uuid(),
    ]);

    foreach ($quantities as $productUuid => $quantity) {
        OrderLine::factory()->for($order)->create([
            'product_uuid' => $productUuid,
            'quantity' => $quantity,
        ]);
    }

    return $order;
}

/*
|--------------------------------------------------------------------------
| Çok Satanlar
|--------------------------------------------------------------------------
*/

it('ranks by units sold and keeps that order through hydration', function (): void {
    $quiet = strippableProduct('Az Satan');
    $loud = strippableProduct('Çok Satan');

    strippableOrder([$loud->uuid => 3, $quiet->uuid => 1]);

    $response = $this->getJson('/api/v1/products/best-sellers')->assertOk();

    /*
     * **THE RANK IS THE WHOLE POINT AND `whereIn` DOES NOT PRESERVE IT.** SQL `IN`
     * is a set: hydrating the ranked uuids without reapplying their positions
     * returns them in whatever order the database felt like, which is usually
     * insertion order — the exact opposite of a best-seller list.
     */
    expect($response->json('data.0.id'))->toBe($loud->uuid)
        ->and($response->json('data.1.id'))->toBe($quiet->uuid);
});

it('counts UNITS rather than baskets, so a bulk buy outranks a popular add-on', function (): void {
    $bulk = strippableProduct('Kutu Kutu');
    $addon = strippableProduct('Ucuz Ek');

    strippableOrder([$bulk->uuid => 10]);
    strippableOrder([$addon->uuid => 1]);
    strippableOrder([$addon->uuid => 1]);

    // Two baskets to one, but ten units to two: the thing people stock up on is
    // the best seller, not the thing that rides along.
    $this->getJson('/api/v1/products/best-sellers')
        ->assertOk()
        ->assertJsonPath('data.0.id', $bulk->uuid);
});

it('does not count a basket nobody paid for', function (): void {
    $product = strippableProduct('Ödenmemiş');

    foreach ([OrderStatus::Pending, OrderStatus::AwaitingPayment, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
        strippableOrder([$product->uuid => 5], $status);
    }

    /*
     * A cart is not a sale, an abandoned card form is not a sale, and an expired
     * order gave its stock back (ADR-072). Ranking on any of them would put a
     * product nobody bought at the top of the homepage.
     */
    $this->getJson('/api/v1/products/best-sellers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('does not count a refunded order, because the money went back', function (): void {
    $returned = strippableProduct('İade Edilen');
    $kept = strippableProduct('Kalan');

    strippableOrder([$returned->uuid => 9], OrderStatus::Refunded);
    strippableOrder([$kept->uuid => 1]);

    $this->getJson('/api/v1/products/best-sellers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->uuid);
});

it('drops a best seller that has gone out of stock or been unpublished', function (): void {
    $soldOut = strippableProduct('Tükenen');
    $withdrawn = strippableProduct('Yayından Kalkan');
    $available = strippableProduct('Rafta Duran');

    foreach ([$soldOut, $withdrawn, $available] as $product) {
        strippableOrder([$product->uuid => 5]);
    }

    // Sold out through the offer action, so Inventory hears about it too.
    $offer = App\Modules\Offer\Domain\Models\Offer::query()->where('product_uuid', $soldOut->uuid)->firstOrFail();
    app(UpdateOfferStockAction::class)->run($offer, new UpdateOfferStockDTO(stockQuantity: 0));

    $withdrawn->forceFill(['status' => App\Modules\Catalog\Domain\Enums\ProductStatus::Draft])->save();

    /*
     * A suggestion the buyer cannot buy is a dead card: they tap it and find
     * nothing for sale. The strip comes back SHORTER rather than wrong.
     */
    $this->getJson('/api/v1/products/best-sellers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->uuid);
});

it('answers an empty list on a shop’s first day', function (): void {
    strippableProduct('Henüz Satılmadı');

    /*
     * **`[]` WITH A 200 IS THE CONTRACT.** The storefront hides a strip that
     * returns nothing and shows it the moment it fills, so an empty answer is the
     * normal early state — not a 404, not a placeholder.
     */
    $this->getJson('/api/v1/products/best-sellers')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => []]);
});

/*
|--------------------------------------------------------------------------
| En Çok Değerlendirilenler
|--------------------------------------------------------------------------
*/

it('ranks by published review count', function (): void {
    $talked = strippableProduct('Çok Yorumlanan');
    $quiet = strippableProduct('Az Yorumlanan');

    Review::factory()->count(5)->create([
        'product_uuid' => $talked->uuid,
        'status' => ReviewStatus::Published,
    ]);
    Review::factory()->count(2)->create([
        'product_uuid' => $quiet->uuid,
        'status' => ReviewStatus::Published,
    ]);

    $response = $this->getJson('/api/v1/products/most-reviewed')->assertOk();

    expect($response->json('data.0.id'))->toBe($talked->uuid)
        ->and($response->json('data.1.id'))->toBe($quiet->uuid);
});

it('ignores a review that is still waiting for a moderator, or was refused', function (): void {
    $product = strippableProduct('Sırada Bekleyen');

    Review::factory()->count(4)->create([
        'product_uuid' => $product->uuid,
        'status' => ReviewStatus::PendingReview,
    ]);
    Review::factory()->create([
        'product_uuid' => $product->uuid,
        'status' => ReviewStatus::Rejected,
    ]);

    /*
     * **PUBLISHED MEANS MODERATED** (ADR-068). A pending review is one nobody has
     * read yet and a rejected one the platform refused; counting either would let
     * a product climb the homepage on text no buyer will ever see.
     */
    $this->getJson('/api/v1/products/most-reviewed')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('answers an empty list when nothing has been reviewed', function (): void {
    strippableProduct('Yorumsuz');

    $this->getJson('/api/v1/products/most-reviewed')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => []]);
});
