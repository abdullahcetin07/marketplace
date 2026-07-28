<?php

declare(strict_types=1);

use App\Models\Seller;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductDrafted;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\CreateProduct;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\EditProduct;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\ListProducts;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Seller panel — "ürün aç" (Catalog §5, entry point 2)
|--------------------------------------------------------------------------
|
| The live end-to-end path, driven through the real Filament components: a
| seller opens a product, generates variants, submits. NOTHING here publishes —
| that is the Category Manager's, and these tests pin that boundary as hard as
| they pin the happy path.
|
| The other thing they pin is the wall: a second seller cannot see the first's
| draft. That is the failure whose blast radius is every seller's unreleased
| product plans.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('seller'));
});

/**
 * A seller who owns an approved company.
 *
 * @return array{seller: Seller, organization: Organization}
 */
function panelSeller(): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->owner()->create();
    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);

    OrganizationMember::factory()
        ->for($organization)
        ->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    return ['seller' => $seller, 'organization' => $organization];
}

/**
 * A leaf category with Renk as a variant axis — the clothing shape.
 *
 * @return array{category: Category, colour: Attribute}
 */
function panelCategory(): array
{
    $root = Category::factory()->create(['name_tr' => 'Giyim']);
    $category = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört']);

    $colour = Attribute::factory()->variantDefining()->withValues(3)->create(['code' => 'renk']);
    $category->schemaAttributes()->attach($colour, ['is_variant_defining' => true]);

    return ['category' => $category, 'colour' => $colour->fresh()];
}

it('opens a product from the seller panel', function (): void {
    Event::fake([ProductDrafted::class]);

    ['seller' => $seller, 'organization' => $organization] = panelSeller();
    ['category' => $category] = panelCategory();

    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'proposed_by_org_id' => $organization->getKey(),
            'category_id' => $category->getKey(),
            'title_tr' => 'Pamuklu Tişört',
            'title_en' => 'Cotton T-Shirt',
            'description_tr' => '%100 pamuk.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->proposed_by_org_id)->toBe($organization->getKey())
        // The pair travels together (ADR-033) — one without the other is a row
        // no production path can produce.
        ->and($product->proposed_by_org_uuid)->toBe($organization->uuid)
        ->and($product->localized('title', 'tr'))->toBe('Pamuklu Tişört');

    Event::assertDispatched(ProductDrafted::class);
});

it('offers only leaf categories on the authoring form', function (): void {
    // §3.2 — a container has no attribute schema, so filing a product against
    // one would leave nothing to satisfy.
    ['seller' => $seller] = panelSeller();
    ['category' => $leaf] = panelCategory();
    $root = $leaf->parent;

    $this->actingAsSeller($seller);

    $options = \App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource::leafCategoryOptions();

    expect($options)->toHaveKey($leaf->getKey())
        ->and($options)->not->toHaveKey($root->getKey());
});

it('refuses a barcode that is already in the catalog, on the field', function (): void {
    // §3.4 — the dedup key, and the moment ADR-037 pays for itself: the seller
    // is told before filling in the rest of the form.
    ['seller' => $seller, 'organization' => $organization] = panelSeller();
    ['category' => $category] = panelCategory();

    Product::factory()->for($category, 'category')->create(['gtin' => '8691234567890']);

    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'proposed_by_org_id' => $organization->getKey(),
            'category_id' => $category->getKey(),
            'title_tr' => 'Aynı Ürün',
            'gtin' => '8691234567890',
        ])
        ->call('create')
        ->assertHasFormErrors(['gtin']);

    expect(Product::query()->count())->toBe(1);
});

it('refuses to file a product against another company', function (): void {
    // The select is scoped to the actor's companies, but that is a UI
    // convenience — the posted id is client input and gets re-checked.
    //
    // The seller is given a SECOND organization on purpose: the picker only
    // renders when there is more than one, so a single-company seller could not
    // post an id at all and this check would never be reached. Testing the
    // easy case would prove nothing.
    ['seller' => $seller] = panelSeller();
    ['organization' => $secondOfMine] = panelSeller();
    ['organization' => $someoneElse] = panelSeller();
    ['category' => $category] = panelCategory();

    OrganizationMember::factory()
        ->for($secondOfMine)
        ->role(OrganizationRole::Manager)
        ->create(['user_id' => $seller->getKey()]);

    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'proposed_by_org_id' => $someoneElse->getKey(),
            'category_id' => $category->getKey(),
            'title_tr' => 'Başkasının Ürünü',
        ])
        ->call('create')
        ->assertHasFormErrors(['proposed_by_org_id']);

    expect(Product::query()->count())->toBe(0);
});

it('hides the company picker from a seller who has only one', function (): void {
    // Asking someone to pick from a list of one is a question with no
    // information in it — and it means a single-company seller cannot post an
    // organization id at all.
    ['seller' => $seller] = panelSeller();
    panelCategory();

    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->assertFormFieldIsHidden('proposed_by_org_id');
});

it('generates variants from the chosen axis values', function (): void {
    // ADR-039/§13.4 — the cartesian, driven through the real relation manager.
    ['seller' => $seller, 'organization' => $organization] = panelSeller();
    ['category' => $category, 'colour' => $colour] = panelCategory();

    $this->actingAsSeller($seller);

    $product = Product::factory()
        ->for($category, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->create();

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callTableAction('generate', data: [
            'axis_'.$colour->getKey() => $colour->values->pluck('id')->all(),
        ]);

    expect($product->fresh()->variants()->count())->toBe(3);

    foreach ($product->fresh()->variants as $variant) {
        expect($variant->attributeValues()->count())->toBe(1)
            ->and($variant->sku)->not->toBeEmpty();
    }
});

it('submits a product into the moderation queue', function (): void {
    ['seller' => $seller, 'organization' => $organization] = panelSeller();
    ['category' => $category] = panelCategory();

    $this->actingAsSeller($seller);

    $product = Product::factory()
        ->for($category, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->create();

    ProductVariant::factory()->for($product)->default()->create();

    Livewire::test(ListProducts::class)
        ->callTableAction('submit', $product);

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview)
        ->and($product->fresh()->submitted_at)->not->toBeNull();
});

it('refuses to submit a product that has no variant', function (): void {
    ['seller' => $seller, 'organization' => $organization] = panelSeller();
    ['category' => $category] = panelCategory();

    $this->actingAsSeller($seller);

    $product = Product::factory()
        ->for($category, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->create();

    Livewire::test(ListProducts::class)
        ->callTableAction('submit', $product);

    // Refused as a notification, not an exception page — an expected domain
    // refusal is not an incident.
    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

it('shows a seller only their own proposals', function (): void {
    // ADR-030/040 — the tenancy wall, exercised through the real table query.
    ['seller' => $mine, 'organization' => $myOrganization] = panelSeller();
    ['organization' => $theirOrganization] = panelSeller();
    ['category' => $category] = panelCategory();

    $myProduct = Product::factory()->for($category, 'category')
        ->proposedBy((int) $myOrganization->getKey(), (string) $myOrganization->uuid)
        ->create(['title_tr' => 'Benim Ürünüm']);

    $theirProduct = Product::factory()->for($category, 'category')
        ->proposedBy((int) $theirOrganization->getKey(), (string) $theirOrganization->uuid)
        ->create(['title_tr' => 'Onların Ürünü']);

    $this->actingAsSeller($mine);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$myProduct])
        ->assertCanNotSeeTableRecords([$theirProduct]);
});

it('does not let a seller reach another seller\'s draft directly', function (): void {
    // Not seeing it in a list is not the same as not being able to open it.
    //
    // The wall answers NOT FOUND rather than forbidden, and that is the better
    // answer: `getEloquentQuery()` is scoped, so another seller's product does
    // not exist as far as this panel is concerned. A 403 would confirm the
    // record exists to someone who should not know that.
    ['seller' => $intruder] = panelSeller();
    ['organization' => $theirOrganization] = panelSeller();
    ['category' => $category] = panelCategory();

    $theirProduct = Product::factory()->for($category, 'category')
        ->proposedBy((int) $theirOrganization->getKey(), (string) $theirOrganization->uuid)
        ->create();

    $this->actingAsSeller($intruder);

    expect(fn () => Livewire::test(EditProduct::class, ['record' => $theirProduct->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('offers no price and no stock field anywhere on the seller form', function (): void {
    // ADR-037, asserted against the rendered form rather than only the schema:
    // the boundary has to hold where a human can actually reach it.
    ['seller' => $seller] = panelSeller();
    panelCategory();

    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->assertFormFieldExists('title_tr')
        ->assertFormFieldExists('category_id')
        ->assertFormFieldDoesNotExist('price')
        ->assertFormFieldDoesNotExist('stock')
        ->assertFormFieldDoesNotExist('quantity');
});
