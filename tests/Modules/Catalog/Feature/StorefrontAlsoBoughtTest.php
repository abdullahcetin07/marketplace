<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Application\Actions\UpdateOfferStockAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| "Bu Ürünü Alanlar Bunları da Aldı" (ADR-077)
|--------------------------------------------------------------------------
|
| Co-occurrence computed on read, with no stored recommendation table. The unit is
| the CHECKOUT GROUP rather than the order: a basket splits into one order per
| seller (ADR-052), so a pair bought from two shops at once is still a pair — and
| on a marketplace that is most of them.
|
*/

beforeEach(function (): void {
    $this->seedAll();

    Cache::flush();
});

/**
 * A published product with a live, in-stock offer.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function coBoughtProduct(string $title): Product
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
        stockQuantity: 10,
    ));

    return $product;
}

/**
 * One basket. Every product lands on its own order under one checkout group,
 * which is what a multi-seller basket actually looks like (ADR-052).
 *
 * @param array<int, string> $productUuids
 */
function coBoughtBasket(array $productUuids, OrderStatus $status = OrderStatus::Paid, int $quantity = 1): string
{
    $group = (string) Str::uuid();

    foreach ($productUuids as $productUuid) {
        /** @var Order $order */
        $order = Order::factory()->status($status)->create(['checkout_group_uuid' => $group]);

        OrderLine::factory()->for($order)->create([
            'product_uuid' => $productUuid,
            'quantity' => $quantity,
        ]);
    }

    return $group;
}

it('recommends what was bought in the same basket, both ways round', function (): void {
    $shampoo = coBoughtProduct('Şampuan');
    $conditioner = coBoughtProduct('Saç Kremi');

    coBoughtBasket([$shampoo->uuid, $conditioner->uuid]);

    $this->getJson("/api/v1/products/{$shampoo->uuid}/also-bought")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $conditioner->uuid);

    $this->getJson("/api/v1/products/{$conditioner->uuid}/also-bought")
        ->assertOk()
        ->assertJsonPath('data.0.id', $shampoo->uuid);
});

it('spans two sellers in one basket, which is the reason the unit is the group', function (): void {
    $fromShopA = coBoughtProduct('A Mağazasından');
    $fromShopB = coBoughtProduct('B Mağazasından');

    /*
     * **TWO ORDERS, ONE BASKET.** `coBoughtBasket` writes a separate order per
     * product precisely because that is what checkout does (ADR-052). Reading
     * co-purchase from a single ORDER would find nothing here — and "nothing"
     * would be wrong about the most ordinary marketplace basket there is.
     */
    $this->getJson("/api/v1/products/{$fromShopA->uuid}/also-bought")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    coBoughtBasket([$fromShopA->uuid, $fromShopB->uuid]);

    Cache::flush();

    $this->getJson("/api/v1/products/{$fromShopA->uuid}/also-bought")
        ->assertOk()
        ->assertJsonPath('data.0.id', $fromShopB->uuid);
});

it('ranks by how many people paired them, not by how many units moved', function (): void {
    $anchor = coBoughtProduct('Ana Ürün');
    $common = coBoughtProduct('Sık Eşlik Eden');
    $bulk = coBoughtProduct('Tek Seferde Çok');

    coBoughtBasket([$anchor->uuid, $common->uuid]);
    coBoughtBasket([$anchor->uuid, $common->uuid]);
    coBoughtBasket([$anchor->uuid, $bulk->uuid], quantity: 20);

    /*
     * Twenty units in one basket is one person's shopping trip; two baskets are
     * two people agreeing. "Bought together" is a question about people, so a
     * single bulk order must not invent a trend.
     */
    $response = $this->getJson("/api/v1/products/{$anchor->uuid}/also-bought")->assertOk();

    expect($response->json('data.0.id'))->toBe($common->uuid)
        ->and($response->json('data.1.id'))->toBe($bulk->uuid);
});

it('ignores baskets nobody paid for', function (): void {
    $anchor = coBoughtProduct('Ödenmiş Ürün');
    $neverPaid = coBoughtProduct('Sepette Kalan');

    coBoughtBasket([$anchor->uuid, $neverPaid->uuid], OrderStatus::AwaitingPayment);
    coBoughtBasket([$anchor->uuid, $neverPaid->uuid], OrderStatus::Cancelled);

    // A cart is not a purchase, and a cancelled basket is a purchase undone.
    $this->getJson("/api/v1/products/{$anchor->uuid}/also-bought")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('never recommends the product itself, and never one that cannot be bought', function (): void {
    $anchor = coBoughtProduct('Ana Ürün');
    $soldOut = coBoughtProduct('Tükenen');
    $unpublished = coBoughtProduct('Yayından Kalkan');

    coBoughtBasket([$anchor->uuid, $soldOut->uuid, $unpublished->uuid]);

    $offer = Offer::query()->where('product_uuid', $soldOut->uuid)->firstOrFail();
    app(UpdateOfferStockAction::class)->run($offer, new UpdateOfferStockDTO(stockQuantity: 0));

    $unpublished->forceFill(['status' => ProductStatus::Draft])->save();

    /*
     * Self-exclusion is not a nicety: a carousel under a product that opens with
     * that same product reads as broken. And a suggestion the buyer cannot buy is
     * a dead card, so the strip comes back empty rather than misleading.
     */
    $this->getJson("/api/v1/products/{$anchor->uuid}/also-bought")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('answers by slug as well as by uuid, and empty when nobody has paired it', function (): void {
    $lonely = coBoughtProduct('Yalnız Ürün');

    /*
     * BY SHAPE, NEVER BY TRIAL (ADR-059). A slug handed to a `uuid` column is a
     * SQLSTATE[22P02] and a 500 on PostgreSQL while every SQLite test passes —
     * this platform has shipped that bug three times.
     */
    $this->getJson("/api/v1/products/{$lonely->slug}/also-bought")
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => []]);

    $this->getJson('/api/v1/products/olmayan-urun/also-bought')->assertNotFound();
});
