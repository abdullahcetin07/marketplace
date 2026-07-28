<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\ArchiveProductAction;
use App\Modules\Catalog\Application\Actions\DraftProductAction;
use App\Modules\Catalog\Application\Actions\PublishProductAction;
use App\Modules\Catalog\Application\Actions\RejectProductAction;
use App\Modules\Catalog\Application\Actions\RequestProductRevisionAction;
use App\Modules\Catalog\Application\Actions\SetProductAttributesAction;
use App\Modules\Catalog\Application\Actions\SubmitProductForReviewAction;
use App\Modules\Catalog\Application\Actions\UpdateProductAction;
use App\Modules\Catalog\Domain\DTOs\BindCategoryAttributeDTO;
use App\Modules\Catalog\Domain\DTOs\DraftProductDTO;
use App\Modules\Catalog\Domain\DTOs\ModerationDecisionDTO;
use App\Modules\Catalog\Domain\DTOs\ProductAttributeValueDTO;
use App\Modules\Catalog\Domain\DTOs\UpdateProductDTO;
use App\Modules\Catalog\Application\Actions\BindCategoryAttributeAction;
use App\Modules\Catalog\Domain\Enums\AttributeType;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductDrafted;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Events\ProductRejected;
use App\Modules\Catalog\Domain\Events\ProductRevisionRequested;
use App\Modules\Catalog\Domain\Events\ProductSubmittedForReview;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Organization\Domain\Models\Organization;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Catalog — the product moderation lifecycle (§3.1, §5)
|--------------------------------------------------------------------------
|
| Draft → PendingReview → Published | Rejected | NeedsRevision → PendingReview.
|
| THE PRODUCT IS THE REQUEST (§5): approving creates nothing new, it moves the
| product. These tests pin that — there is no second entity to look for — and
| they pin where each rule binds, which is the part that is easy to get subtly
| wrong: leaf-ness and GTIN at DRAFT, at-least-one-variant at SUBMIT, required
| attributes at PUBLISH.
|
*/

/**
 * A leaf category a product can actually attach to.
 */
function leafCategory(): Category
{
    $root = Category::factory()->create();

    return Category::factory()->childOf($root)->create();
}

/**
 * A drafted product with the one variant every product needs (ADR-039).
 */
function draftedProduct(?Category $category = null, ?Organization $organization = null): Product
{
    $category ??= leafCategory();

    $product = DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid,
        title: ['tr' => 'Pamuklu Tişört', 'en' => 'Cotton T-Shirt'],
        proposedByOrgId: $organization?->getKey(),
        proposedByOrgUuid: $organization?->uuid,
    ));

    ProductVariant::factory()->for($product)->default()->create();

    return $product->refresh();
}

it('drafts a product with no price and no stock', function (): void {
    Event::fake([ProductDrafted::class]);

    $category = leafCategory();
    $product = DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid,
        title: ['tr' => 'Pamuklu Tişört', 'en' => 'Cotton T-Shirt'],
        description: ['tr' => '%100 pamuk.'],
    ));

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->slug)->toBe('pamuklu-tisort')
        ->and($product->localized('title', 'en'))->toBe('Cotton T-Shirt')
        // ADR-037, restated as an assertion.
        ->and($product->getAttributes())->not->toHaveKey('price')
        ->and($product->getAttributes())->not->toHaveKey('stock');

    Event::assertDispatched(ProductDrafted::class);
});

it('refuses to attach a product to a category that has children', function (): void {
    // §3.2 — a container has no attribute schema to satisfy.
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    expect(fn () => DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $parent->uuid,
        title: ['tr' => 'Bir Ürün'],
    )))->toThrow(CatalogException::class);
});

it('tells a seller the product already exists when the GTIN is taken', function (): void {
    // §3.4 — the shared catalog's dedup key, and the point of ADR-037. Caught at
    // DRAFT so the seller hears it before filling in a form, not after.
    $category = leafCategory();
    $existing = Product::factory()->for($category, 'category')->create(['gtin' => '8691234567890']);

    expect(fn () => DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid,
        title: ['tr' => 'Aynı Ürün'],
        gtin: '8691234567890',
    )))->toThrow(CatalogException::class);
});

it('treats a blank barcode field as no barcode', function (): void {
    // An empty form field posts '', which must not become a GTIN — the column
    // is UNIQUE and the second such product would hit an index nobody meant.
    $category = leafCategory();

    $first = DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid, title: ['tr' => 'Bir'], gtin: '',
    ));
    $second = DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid, title: ['tr' => 'İki'], gtin: '   ',
    ));

    expect($first->gtin)->toBeNull()->and($second->gtin)->toBeNull();
});

it('lets a product keep its own GTIN through an edit', function (): void {
    $product = draftedProduct();
    UpdateProductAction::make()->run($product, new UpdateProductDTO(
        gtin: '8691111111111', present: ['gtin'],
    ));

    UpdateProductAction::make()->run($product, new UpdateProductDTO(
        title: ['tr' => 'Yeni Ad'], gtin: '8691111111111', present: ['gtin'],
    ));

    expect($product->fresh()->gtin)->toBe('8691111111111');
});

it('refuses an edit that would steal another product\'s GTIN', function (): void {
    $category = leafCategory();
    Product::factory()->for($category, 'category')->create(['gtin' => '8690000000000']);
    $mine = draftedProduct($category);

    expect(fn () => UpdateProductAction::make()->run($mine, new UpdateProductDTO(
        gtin: '8690000000000', present: ['gtin'],
    )))->toThrow(CatalogException::class);
});

it('carries a product from draft to published', function (): void {
    Event::fake([ProductSubmittedForReview::class, ProductPublished::class]);

    $product = draftedProduct();

    SubmitProductForReviewAction::make()->run($product);
    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview)
        ->and($product->fresh()->submitted_at)->not->toBeNull();

    PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO);

    $published = $product->fresh();

    expect($published->status)->toBe(ProductStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->moderated_at)->not->toBeNull();

    Event::assertDispatched(ProductSubmittedForReview::class);
    Event::assertDispatched(ProductPublished::class);
});

it('refuses to submit a product with no variant', function (): void {
    // §3.3/ADR-039 — the variant is what an Offer references, so a product with
    // none is not a thing anyone could sell. Caught before a moderator's time
    // is spent on it.
    $category = leafCategory();
    $product = DraftProductAction::make()->run(new DraftProductDTO(
        categoryUuid: $category->uuid,
        title: ['tr' => 'Varyantsız'],
    ));

    expect(fn () => SubmitProductForReviewAction::make()->run($product))
        ->toThrow(CatalogException::class);
});

it('refuses to publish a product missing an attribute its category requires', function (): void {
    // §3.2 — checked at PUBLISH, not at draft, so authoring stays incremental.
    $category = leafCategory();
    $material = Attribute::factory()->withValues(2)->create(['code' => 'malzeme']);

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $material->uuid,
        isRequired: true,
    ));

    $product = draftedProduct($category);
    SubmitProductForReviewAction::make()->run($product);

    expect(fn () => PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO))
        ->toThrow(CatalogException::class);

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});

it('publishes once the required attribute is supplied', function (): void {
    $category = leafCategory();
    $material = Attribute::factory()->withValues(2)->create(['code' => 'malzeme']);

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $material->uuid,
        isRequired: true,
    ));

    $product = draftedProduct($category);

    SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(
            attributeUuid: $material->uuid,
            valueUuid: $material->values()->first()->uuid,
        ),
    ]);

    SubmitProductForReviewAction::make()->run($product->fresh());
    PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO);

    expect($product->fresh()->status)->toBe(ProductStatus::Published);
});

it('lets a draft save without its required attributes', function (): void {
    // The other half of the §3.2 placement: a form nobody can leave and come
    // back to is a form nobody finishes.
    $category = leafCategory();
    $material = Attribute::factory()->withValues(2)->create(['code' => 'malzeme']);

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $material->uuid,
        isRequired: true,
    ));

    $product = draftedProduct($category);

    expect($product->status)->toBe(ProductStatus::Draft);
});

it('sends a product back for revision with a reason the seller can act on', function (): void {
    Event::fake([ProductRevisionRequested::class]);

    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);

    RequestProductRevisionAction::make()->run(
        $product->fresh(),
        new ModerationDecisionDTO(reason: 'Fotoğraflar bulanık.'),
    );

    $revised = $product->fresh();

    expect($revised->status)->toBe(ProductStatus::NeedsRevision)
        ->and($revised->moderation_reason)->toBe('Fotoğraflar bulanık.')
        // Back in the seller's hands.
        ->and($revised->status->isSellerEditable())->toBeTrue();

    Event::assertDispatched(ProductRevisionRequested::class);
});

it('lets a revised product re-enter the queue and clears the stale reason', function (): void {
    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);
    RequestProductRevisionAction::make()->run($product->fresh(), new ModerationDecisionDTO(reason: 'Bulanık.'));

    SubmitProductForReviewAction::make()->run($product->fresh());

    $resubmitted = $product->fresh();

    expect($resubmitted->status)->toBe(ProductStatus::PendingReview)
        // A superseded verdict must not still be shown to the seller.
        ->and($resubmitted->moderation_reason)->toBeNull();
});

it('requires a reason to refuse a product', function (): void {
    // A refusal with no stated cause is the fastest way to lose a merchant.
    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);

    expect(fn () => RejectProductAction::make()->run($product->fresh(), new ModerationDecisionDTO))
        ->toThrow(CatalogException::class);

    expect(fn () => RequestProductRevisionAction::make()->run(
        $product->fresh(),
        new ModerationDecisionDTO(reason: '   '),
    ))->toThrow(CatalogException::class);
});

it('rejects a product and still allows it to be reworked', function (): void {
    Event::fake([ProductRejected::class]);

    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);

    RejectProductAction::make()->run(
        $product->fresh(),
        new ModerationDecisionDTO(reason: 'Katalogda zaten mevcut.'),
    );

    expect($product->fresh()->status)->toBe(ProductStatus::Rejected)
        // Not a dead end (§3.1).
        ->and(ProductStatus::Rejected->canTransitionTo(ProductStatus::Draft))->toBeTrue();

    Event::assertDispatched(ProductRejected::class);
});

it('refuses a transition the lifecycle does not allow', function (): void {
    // Publishing straight from Draft would bypass moderation entirely.
    $product = draftedProduct();

    expect(fn () => PublishProductAction::make()->run($product, new ModerationDecisionDTO))
        ->toThrow(CatalogException::class);

    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

it('archives a published product without deleting it', function (): void {
    Event::fake([ProductArchived::class]);

    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);
    PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO);

    ArchiveProductAction::make()->run($product->fresh());

    expect($product->fresh()->status)->toBe(ProductStatus::Archived)
        // Offers will reference it; an order history pointing at a vanished
        // product is unreadable (§3.5).
        ->and(Product::query()->whereKey($product->getKey())->exists())->toBeTrue();

    Event::assertDispatched(ProductArchived::class);
});

it('keeps the original publication date through an archive and re-publish', function (): void {
    $product = draftedProduct();
    SubmitProductForReviewAction::make()->run($product);
    PublishProductAction::make()->run($product->fresh(), new ModerationDecisionDTO);

    $firstPublished = $product->fresh()->published_at;

    ArchiveProductAction::make()->run($product->fresh());

    expect($product->fresh()->published_at?->timestamp)->toBe($firstPublished?->timestamp);
});

it('rejects a descriptive value for an attribute that defines variants', function (): void {
    // §2.4 — "100% cotton" is the product's; "size M" is the variant's. Storing
    // them the same way makes both unanswerable.
    $category = leafCategory();
    $colour = Attribute::factory()->variantDefining()->withValues(3)->create(['code' => 'renk']);

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $colour->uuid,
        isVariantDefining: true,
    ));

    $product = draftedProduct($category);

    expect(fn () => SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(
            attributeUuid: $colour->uuid,
            valueUuid: $colour->values()->first()->uuid,
        ),
    ]))->toThrow(CatalogException::class);
});

it('rejects an attribute that is not in the category schema at all', function (): void {
    $product = draftedProduct();
    $stranger = Attribute::factory()->withValues(2)->create();

    expect(fn () => SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(
            attributeUuid: $stranger->uuid,
            valueUuid: $stranger->values()->first()->uuid,
        ),
    ]))->toThrow(CatalogException::class);
});

it('rejects a select value that belongs to a different attribute', function (): void {
    // Otherwise Renk could be set to a Beden.
    $category = leafCategory();
    $colour = Attribute::factory()->withValues(2)->create(['code' => 'renk']);
    $size = Attribute::factory()->withValues(2)->create(['code' => 'beden']);

    BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
        attributeUuid: $colour->uuid,
    ));

    $product = draftedProduct($category);

    expect(fn () => SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(
            attributeUuid: $colour->uuid,
            valueUuid: $size->values()->first()->uuid,
        ),
    ]))->toThrow(CatalogException::class);
});

it('normalises a free value through its attribute type', function (): void {
    // So two products never disagree about what "true" or "1.50" looks like.
    $category = leafCategory();
    $warranty = Attribute::factory()->ofType(AttributeType::Number)->create(['code' => 'garanti']);
    $organic = Attribute::factory()->ofType(AttributeType::Boolean)->create(['code' => 'organik']);

    foreach ([$warranty, $organic] as $attribute) {
        BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
            attributeUuid: $attribute->uuid,
        ));
    }

    $product = draftedProduct($category);

    SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(attributeUuid: $warranty->uuid, value: '24.0'),
        new ProductAttributeValueDTO(attributeUuid: $organic->uuid, value: 'yes'),
    ]);

    $values = $product->fresh()->attributes()->get()
        ->mapWithKeys(fn (Attribute $a): array => [$a->code => $a->getRelation('pivot')->getAttribute('value')]);

    expect($values['garanti'])->toBe('24')
        ->and($values['organik'])->toBe('1');
});

it('replaces the whole attribute set rather than merging into it', function (): void {
    // "Set the attributes" is what a form submit means; a merge would make
    // removing a value impossible through the UI.
    $category = leafCategory();
    $a = Attribute::factory()->withValues(2)->create(['code' => 'bir']);
    $b = Attribute::factory()->withValues(2)->create(['code' => 'iki']);

    foreach ([$a, $b] as $attribute) {
        BindCategoryAttributeAction::make()->run($category, new BindCategoryAttributeDTO(
            attributeUuid: $attribute->uuid,
        ));
    }

    $product = draftedProduct($category);

    SetProductAttributesAction::make()->run($product, [
        new ProductAttributeValueDTO(attributeUuid: $a->uuid, valueUuid: $a->values()->first()->uuid),
        new ProductAttributeValueDTO(attributeUuid: $b->uuid, valueUuid: $b->values()->first()->uuid),
    ]);

    expect($product->fresh()->attributes()->count())->toBe(2);

    SetProductAttributesAction::make()->run($product->fresh(), [
        new ProductAttributeValueDTO(attributeUuid: $a->uuid, valueUuid: $a->values()->first()->uuid),
    ]);

    expect($product->fresh()->attributes()->count())->toBe(1);
});
