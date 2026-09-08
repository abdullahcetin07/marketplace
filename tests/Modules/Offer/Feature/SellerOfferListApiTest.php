<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| GET /api/v1/seller/offers — the feed's read half (ADR-076)
|--------------------------------------------------------------------------
|
| The feed could write and not read, so an integration had to guess which
| barcodes matched, which offers are paused and what stock the platform thinks
| is on the shelf. Three things matter here and they are all about trust: the
| row is keyed by the GTIN the seller pushes (not a uuid their ERP has never
| seen), money leaves as a decimal string, and the scope is the token's — there
| is no parameter that widens it to somebody else's shop.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller with a live shop and one offer on a published product.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, offer: Offer, gtin: string, title: string}
 */
function listApiSeller(string $gtin = '08690000009911', int $stock = 7): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'gtin' => $gtin,
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);

    // The title is read back from the CATALOGUE, never denormalised onto the
    // offer (ADR-037), and it is the LOCALIZED one — so the fixture carries the
    // value the request's locale will resolve rather than a literal that only
    // holds while the suite runs in Turkish.
    // BOTH LOCALES, deliberately: the row carries the LOCALIZED title, so a
    // fixture that filled only Turkish would assert the request's locale rather
    // than the endpoint's behaviour.
    $product->forceFill([
        'title_tr' => 'Listelenen Ürün 7,5 ml',
        'title_en' => 'Listelenen Ürün 7,5 ml',
    ])->save();
    $variant = ProductVariant::factory()->for($product)->create(['is_default' => true]);

    $offer = Offer::factory()->create([
        'variant_uuid' => $variant->uuid,
        'product_uuid' => $product->uuid,
        'selling_org_id' => $organization->getKey(),
        'selling_org_uuid' => $organization->uuid,
        'store_uuid' => $store->uuid,
        'price_minor' => 12_900,
        'list_price_minor' => 15_900,
        'stock_quantity' => $stock,
        'status' => OfferStatus::Active,
    ]);

    return [
        'seller' => $seller,
        'org' => $organization,
        'offer' => $offer,
        'gtin' => $gtin,
        'title' => 'Listelenen Ürün 7,5 ml',
    ];
}

function listApiToken(Seller $seller): string
{
    return $seller->createToken('erp')->plainTextToken;
}

it('answers a seller their own offers, keyed by the barcode they push', function (): void {
    $fixture = listApiSeller();

    $this->withHeader('Authorization', 'Bearer '.listApiToken($fixture['seller']))
        ->getJson('/api/v1/seller/offers')
        ->assertOk()
        ->assertJsonPath('data.0.gtin', $fixture['gtin'])
        ->assertJsonPath('data.0.title', $fixture['title'])
        ->assertJsonPath('data.0.stock', 7)
        ->assertJsonPath('data.0.status', 'active')
        // MONEY IS A DECIMAL STRING (005 §28) — `price_minor` never leaves.
        ->assertJsonPath('data.0.price', '129.00')
        ->assertJsonPath('data.0.list_price', '159.00')
        ->assertJsonPath('meta.total', 1);
});

it('shows a seller nothing of another shop, and has no parameter to ask with', function (): void {
    /*
     * **THE WHOLE AUTHORIZATION MODEL.** The merchant is resolved from the token,
     * so there is no `org` to tamper with — the attack surface is the token
     * itself. A second seller's offer must simply not be in the page.
     */
    $mine = listApiSeller();
    $theirs = listApiSeller('08690000009922');

    $response = $this->withHeader('Authorization', 'Bearer '.listApiToken($mine['seller']))
        ->getJson('/api/v1/seller/offers?org='.$theirs['org']->uuid)
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.gtin'))->toBe($mine['gtin']);
});

it('refuses a customer token and an anonymous call alike', function (): void {
    // Guard isolation, not a policy: `auth:sanctum_seller` is bound to `sellers`.
    $this->getJson('/api/v1/seller/offers')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer '.Customer::factory()->create()->createToken('erp')->plainTextToken)
        ->getJson('/api/v1/seller/offers')
        ->assertUnauthorized();
});

it('filters by status and by whether there is stock on the shelf', function (): void {
    // The two questions a replenishment run asks before it decides what to push.
    $fixture = listApiSeller();
    $token = listApiToken($fixture['seller']);

    $fixture['offer']->update(['status' => OfferStatus::Paused, 'stock_quantity' => 0]);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/seller/offers?status=active')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/seller/offers?status=paused')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/seller/offers?in_stock=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/seller/offers?in_stock=0')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('clamps the page size so one call cannot ask for the whole table', function (): void {
    $fixture = listApiSeller();

    $this->withHeader('Authorization', 'Bearer '.listApiToken($fixture['seller']))
        ->getJson('/api/v1/seller/offers?per_page=5000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 200);
});

it('lists an offer whose catalogue entry lost its barcode, with gtin null', function (): void {
    /*
     * A row the seller cannot address by GTIN is still THEIR offer, and hiding it
     * would make the list quietly disagree with their own books. Null says so.
     */
    $fixture = listApiSeller();

    Product::query()->where('uuid', $fixture['offer']->product_uuid)->update(['gtin' => null]);
    ProductVariant::query()->where('uuid', $fixture['offer']->variant_uuid)->update(['barcode' => null]);

    $this->withHeader('Authorization', 'Bearer '.listApiToken($fixture['seller']))
        ->getJson('/api/v1/seller/offers')
        ->assertOk()
        ->assertJsonPath('data.0.gtin', null)
        ->assertJsonPath('meta.total', 1);
});
