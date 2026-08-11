<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Offer\Domain\Models\Offer;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| P2 — the feed over HTTP (ADR-076)
|--------------------------------------------------------------------------
|
| **A BATCH IS A REPORT, NOT A TRANSACTION.** The response is 200 with a per-item
| result even when items failed, because a seller pushing four thousand SKUs needs
| to know which forty were rejected — an all-or-nothing 422 would discard 3,960
| good updates over a handful of stale barcodes.
|
| The other half is that a token cannot name somebody else's shop: there is no
| organization field in the payload at all, so the tests below attack the only
| surface that exists — the token itself.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who can actually list, plus a published product to list against.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, org: Organization, gtin: string, variant: ProductVariant}
 */
function feedApiSeller(string $gtin = '08690000004321'): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create([
        'gtin' => $gtin,
        'tax_rate_id' => TaxRate::factory()->rate('0.2000')->create()->getKey(),
    ]);
    $variant = ProductVariant::factory()->for($product)->create(['is_default' => true]);

    return ['seller' => $seller, 'org' => $organization, 'gtin' => $gtin, 'variant' => $variant];
}

it('refuses a call with no token at all', function (): void {
    $this->postJson('/api/v1/seller/offers/sync', ['items' => [['gtin' => '1', 'price' => '1.00', 'stock' => 1]]])
        ->assertUnauthorized();
});

it('refuses a customer or an admin holding a valid token', function (): void {
    /*
     * **GUARD ISOLATION, WHICH IS A PRIVILEGE QUESTION RATHER THAN A UI ONE.**
     * `auth:sanctum` consults all three guards (config/sanctum.php), so a customer
     * or admin token authenticates perfectly well here; what keeps them out of a
     * seller's write surface is the actor-type check in the form request, and it
     * is asserted because nothing else would catch its removal.
     */
    foreach ([['customer', Customer::factory()->create()], ['admin', Admin::factory()->create()]] as [$guard, $stranger]) {
        $this->actingAs($stranger, $guard)
            ->postJson('/api/v1/seller/offers/sync', [
                'items' => [['gtin' => '08690000004321', 'price' => '10.00', 'stock' => 1]],
            ])
            ->assertForbidden();
    }
});

it('creates an offer from a token push, and Inventory sees the stock', function (): void {
    $fixture = feedApiSeller();

    $response = $this->actingAs($fixture['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => [['gtin' => $fixture['gtin'], 'price' => '129,90', 'stock' => 12]],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.processed', 1)
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.items.0.status', 'created');

    /*
     * **A COMMA IS A DECIMAL POINT IN TURKISH** and Excel writes it that way, so
     * "129,90" must be 12 990 kuruş rather than a rejection or — worse — 129.
     */
    expect(Offer::query()->firstOrFail()->price_minor)->toBe(12_990);

    // AND THE STOCK REACHED INVENTORY, which is only true if the feed drove the
    // offer actions rather than writing the model.
    expect(app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(12);
});

it('keeps the good items of a mixed batch and reports the bad ones, with 200', function (): void {
    $fixture = feedApiSeller();

    $response = $this->actingAs($fixture['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => [
                ['gtin' => $fixture['gtin'], 'price' => '99.90', 'stock' => 4],
                ['gtin' => '08699999999999', 'price' => '10.00', 'stock' => 1],
            ],
        ]);

    /*
     * **200, NOT 422.** Forty stale barcodes in a four-thousand-item push must not
     * cost the seller the 3,960 that were fine — the batch is a report, and the
     * caller's system branches on the per-item reason.
     */
    $response->assertOk()
        ->assertJsonPath('data.processed', 2)
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.failed', 1)
        ->assertJsonPath('data.items.1.status', 'failed')
        ->assertJsonPath('data.items.1.reason', 'product_not_in_catalog');

    expect(Offer::query()->count())->toBe(1);
});

it('refuses a batch over the ceiling rather than silently truncating it', function (): void {
    $fixture = feedApiSeller();

    config(['offer.feed.max_batch' => 2]);

    $this->actingAs($fixture['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => array_fill(0, 3, ['gtin' => $fixture['gtin'], 'price' => '10.00', 'stock' => 1]),
        ])
        ->assertUnprocessable();

    /*
     * PROCESSING THE FIRST N WOULD BE THE DANGEROUS KINDNESS: the seller's system
     * would read success while three quarters of the catalogue went nowhere.
     */
    expect(Offer::query()->count())->toBe(0);
});

it('rejects a JSON float price at the boundary, before it can become one', function (): void {
    $fixture = feedApiSeller();

    // 129.90 as a JSON number is 129.89999999999998 in transit — the exact thing
    // the minor-units rule exists to keep out (ADR-005). A string is required.
    $this->actingAs($fixture['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => [['gtin' => $fixture['gtin'], 'price' => 129.90, 'stock' => 1]],
        ])
        ->assertUnprocessable();
});

it('runs the stock-only and withdraw doors over HTTP', function (): void {
    $fixture = feedApiSeller();
    $seller = $this->actingAs($fixture['seller'], 'seller');

    $seller->postJson('/api/v1/seller/offers/sync', [
        'items' => [['gtin' => $fixture['gtin'], 'price' => '50.00', 'stock' => 10]],
    ])->assertOk();

    $seller->postJson('/api/v1/seller/offers/stock', [
        'items' => [['gtin' => $fixture['gtin'], 'stock' => 2]],
    ])->assertOk()->assertJsonPath('data.updated', 1);

    expect(app(InventoryQueryContract::class)
        ->availableFor($fixture['variant']->uuid, $fixture['org']->uuid))->toBe(2);

    $seller->postJson('/api/v1/seller/offers/withdraw', [
        'items' => [['gtin' => $fixture['gtin']]],
    ])->assertOk()->assertJsonPath('data.updated', 1);

    expect(Offer::query()->count())->toBe(0)
        ->and(Offer::withTrashed()->count())->toBe(1);
});

it('writes for the token’s own org and has nowhere to name another', function (): void {
    $mine = feedApiSeller('08690000005001');
    $theirs = feedApiSeller('08690000005002');

    // The other seller's product, pushed with MY token.
    $this->actingAs($mine['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => [['gtin' => $theirs['gtin'], 'price' => '10.00', 'stock' => 1]],
        ])
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    /*
     * **THE OFFER LANDS ON MY ORG, NOT THEIRS** — which is the point. The
     * catalogue is SHARED (ADR-037), so listing against another seller's product
     * is ordinary competition; what must be impossible is writing an offer that
     * BELONGS to them, and the payload has no field for it.
     */
    $offer = Offer::query()->firstOrFail();

    expect($offer->selling_org_id)->toBe((int) $mine['org']->getKey())
        ->and($offer->selling_org_id)->not->toBe((int) $theirs['org']->getKey());
});

it('refuses a seller whose shop is not live', function (): void {
    $fixture = feedApiSeller();

    Store::query()->where('organization_id', $fixture['org']->getKey())
        ->update(['status' => StoreStatus::Suspended]);

    /*
     * REFUSED FOR THE WHOLE CALL, not per item: nothing this token sends could be
     * written anywhere, and defaulting to some store would create offers nobody
     * can see.
     */
    $this->actingAs($fixture['seller'], 'seller')
        ->postJson('/api/v1/seller/offers/sync', [
            'items' => [['gtin' => $fixture['gtin'], 'price' => '10.00', 'stock' => 1]],
        ])
        ->assertForbidden();

    expect(Offer::query()->count())->toBe(0);
});
