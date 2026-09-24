<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Presentation\Filament\Seller\Resources\OfferResource\Pages\ListOffers;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A page of offers costs a fixed number of catalogue queries
|--------------------------------------------------------------------------
|
| An offer holds uuids and renders a title (ADR-037), so the labels are fetched.
| `CatalogLabels` documented a memo and a `prime()` that "the resources below"
| called — and neither worked: nothing primed, and the class was unbound, so
| `app(CatalogLabels::class)` built a fresh instance for every cell whose cache
| was read once and thrown away.
|
| **COUNTING QUERIES IS THE ONLY WAY TO SEE THIS.** Both halves render identical
| HTML whether they cost two queries or sixty; a test that asserts the title is
| on the page passes just as happily against the N+1 it replaced.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A seller with `$count` offers, each on a DIFFERENT product — the shape that
 * makes an unprimed page cost one query per row.
 *
 * @return array{seller: App\Models\Seller, org: Organization}
 */
function sellerWithOffers(int $count): array
{
    /** @var App\Models\Seller $seller */
    $seller = App\Models\Seller::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
    ]);

    $parent = Category::factory()->create();

    for ($i = 0; $i < $count; $i++) {
        $category = Category::factory()->childOf($parent)->create();
        $product = Product::factory()->for($category, 'category')->published()
            ->create(['title_tr' => 'Ürün '.$i]);
        $variant = ProductVariant::factory()->for($product)->create();

        app(CreateOfferAction::class)->run(new CreateOfferDTO(
            variantUuid: $variant->uuid,
            sellingOrgId: $organization->getKey(),
            sellingOrgUuid: $organization->uuid,
            storeUuid: $store->uuid,
            priceMinor: 10_000 + $i,
            stockQuantity: 5,
        ));
    }

    return ['seller' => $seller, 'org' => $organization];
}

/**
 * Catalogue lookups made while rendering the list.
 */
function catalogQueriesRendering(): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'from "products"') || str_contains($query->sql, 'from "product_variants"')) {
            $count++;
        }
    });

    Livewire::test(ListOffers::class)->assertOk();

    return $count;
}

it('resolves a page of labels in a fixed number of queries, not one per row', function (): void {
    $fixture = sellerWithOffers(6);
    $this->actingAsSeller($fixture['seller']);

    $queries = catalogQueriesRendering();

    /*
     * SIX OFFERS ON SIX PRODUCTS. Unprimed and unbound this was two lookups per
     * row — twelve and climbing with the page size. Primed, the page asks once
     * for the products and once for the variants.
     *
     * The bound is deliberately loose rather than exact: the resource is free to
     * read something else from the catalogue tomorrow, and a test that breaks on
     * a legitimate second query teaches people to raise the number instead of
     * asking why. What must never come back is the SHAPE — a count that grows
     * with the rows.
     */
    expect($queries)->toBeLessThanOrEqual(4);
});

it('does not grow that cost when the page does', function (): void {
    $few = sellerWithOffers(3);
    $this->actingAsSeller($few['seller']);
    $small = catalogQueriesRendering();

    $many = sellerWithOffers(12);
    $this->actingAsSeller($many['seller']);
    $large = catalogQueriesRendering();

    // FOUR TIMES THE ROWS, THE SAME NUMBER OF QUERIES. This is the assertion the
    // feature is actually about; the absolute number above is a sanity bound.
    expect($large)->toBe($small);
});
