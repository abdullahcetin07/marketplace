<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages\ListProductModeration;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\CreateProduct;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\EditProduct;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\Pages\ListProducts;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use Database\Modules\Catalog\Seeders\CatalogTaxonomySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Catalog — the live end-to-end path (Phase 1 acceptance)
|--------------------------------------------------------------------------
|
| One test that walks the whole thing the way two humans would, through the real
| Filament components against the real starter taxonomy:
|
|   seller at /seller  → "ürün aç": leaf category, attributes, variants, submit
|   admin  at /admin   → moderation queue: review, approve
|                      → product is Published and indexable
|   second seller      → cannot see the first's draft
|
| The per-surface tests cover the branches. This one exists so the SEAMS between
| them are exercised at least once in the order a real user meets them — the
| defects that survive per-surface tests are the ones in the joins.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * @return array{seller: Seller, organization: Organization}
 */
function e2eSeller(): array
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

it('carries a product from a seller opening it to a published catalog entry', function (): void {
    // The real starter taxonomy (§13.3), not a hand-built fixture — this also
    // proves the seeder produces a tree a seller can actually file against.
    (new CatalogTaxonomySeeder)->run();

    $leaf = Category::query()->where('slug', 'kadin-giyim')->firstOrFail();
    $colour = Attribute::query()->where('code', 'renk')->firstOrFail();
    $size = Attribute::query()->where('code', 'beden')->firstOrFail();
    $material = Attribute::query()->where('code', 'malzeme')->firstOrFail();

    // A Category Manager binds the schema: Renk and Beden are axes here, Malzeme
    // is descriptive and required.
    $leaf->schemaAttributes()->attach($colour, ['is_variant_defining' => true]);
    $leaf->schemaAttributes()->attach($size, ['is_variant_defining' => true]);
    $leaf->schemaAttributes()->attach($material, ['is_required' => true]);

    /*
    |----------------------------------------------------------------------
    | 1. The seller opens a product at /seller
    |----------------------------------------------------------------------
    */
    ['seller' => $seller, 'organization' => $organization] = e2eSeller();

    Filament::setCurrentPanel(Filament::getPanel('seller'));
    $this->actingAsSeller($seller);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'category_id' => $leaf->getKey(),
            'title_tr' => 'Pamuklu Kadın Tişört',
            'title_en' => 'Women\'s Cotton T-Shirt',
            'description_tr' => '%100 pamuklu, günlük kullanıma uygun.',
            'gtin' => '8691234567890',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('gtin', '8691234567890')->firstOrFail();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->proposed_by_org_id)->toBe($organization->getKey());

    /*
    |----------------------------------------------------------------------
    | 2. Sets the required attribute and generates variants
    |----------------------------------------------------------------------
    */
    $product->descriptiveAttributes()->attach($material, [
        'attribute_value_id' => $material->values()->first()->getKey(),
    ]);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])->callTableAction('generate', data: [
        'axis_'.$colour->getKey() => $colour->values()->limit(2)->pluck('id')->all(),
        'axis_'.$size->getKey() => $size->values()->limit(3)->pluck('id')->all(),
    ]);

    // 2 colours × 3 sizes.
    expect($product->fresh()->variants()->count())->toBe(6);

    /*
    |----------------------------------------------------------------------
    | 3. Submits for review
    |----------------------------------------------------------------------
    */
    Livewire::test(ListProducts::class)->callTableAction('submit', $product);

    // Re-read: the action wrote through its own instance, and the moderation
    // step below asks THIS object whether its verdict buttons apply.
    $product = $product->fresh();

    expect($product->status)->toBe(ProductStatus::PendingReview);

    /*
    |----------------------------------------------------------------------
    | 4. A second seller cannot see it
    |----------------------------------------------------------------------
    */
    ['seller' => $otherSeller] = e2eSeller();
    $this->actingAsSeller($otherSeller);

    Livewire::test(ListProducts::class)->assertCanNotSeeTableRecords([$product]);

    /*
    |----------------------------------------------------------------------
    | 5. The Category Manager approves it at /admin
    |----------------------------------------------------------------------
    */
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    /** @var Admin $manager */
    $manager = $this->actingAsAdmin();
    $manager->syncRoles([config('marketplace.roles.category_manager')]);
    $manager->refresh()->loadMissing('roles.permissions', 'permissions');

    Livewire::test(ListProductModeration::class)
        ->assertCanSeeTableRecords([$product])
        ->callTableAction('publish', $product);

    $published = $product->fresh();

    /*
    |----------------------------------------------------------------------
    | 6. Published, indexable, and still without a price
    |----------------------------------------------------------------------
    */
    expect($published->status)->toBe(ProductStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->moderated_by)->toBe($manager->getKey())
        ->and($published->shouldBeSearchable())->toBeTrue();

    $document = $published->load($published->searchRelations())->toSearchableArray();

    expect($document['id'])->toBe($published->uuid)
        ->and($document['skus'])->toHaveCount(6)
        ->and($document)->not->toHaveKey('price')
        ->and($document)->not->toHaveKey('stock');

    // ADR-037 restated at the end of the path: the product is now the
    // platform's, and the seller who opened it holds no price on it — because
    // there is nowhere to put one.
    expect($published->getAttributes())->not->toHaveKey('price');
});

it('sends a product back and lets the seller fix it and re-submit', function (): void {
    // The NeedsRevision loop end to end — the humane path, and the one most
    // likely to be broken by a missing state transition.
    (new CatalogTaxonomySeeder)->run();
    $leaf = Category::query()->where('slug', 'telefon')->firstOrFail();

    ['seller' => $seller, 'organization' => $organization] = e2eSeller();

    $product = Product::factory()
        ->for($leaf, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->pendingReview()
        ->create();

    \Database\Modules\Catalog\Factories\ProductVariantFactory::new()
        ->for($product)->default()->create();

    // Moderator sends it back.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $manager = $this->actingAsAdmin();
    $manager->syncRoles([config('marketplace.roles.category_manager')]);
    $manager->refresh()->loadMissing('roles.permissions', 'permissions');

    Livewire::test(ListProductModeration::class)
        ->callTableAction('request_revision', $product, data: [
            'reason' => 'Görseller ürünü göstermiyor.',
        ]);

    expect($product->fresh()->status)->toBe(ProductStatus::NeedsRevision)
        ->and($product->fresh()->moderation_reason)->toBe('Görseller ürünü göstermiyor.');

    // The seller gets it back, editable, with the reason visible.
    Filament::setCurrentPanel(Filament::getPanel('seller'));
    $this->actingAsSeller($seller);

    expect($seller->can('update', $product->fresh()))->toBeTrue();

    Livewire::test(ListProducts::class)->callTableAction('submit', $product->fresh());

    $resubmitted = $product->fresh();

    expect($resubmitted->status)->toBe(ProductStatus::PendingReview)
        // The superseded verdict is cleared, so the seller is not still shown a
        // reason for a decision that no longer stands.
        ->and($resubmitted->moderation_reason)->toBeNull();
});
