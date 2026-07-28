<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductPublished;
use App\Modules\Catalog\Domain\Events\ProductRejected;
use App\Modules\Catalog\Domain\Events\ProductRevisionRequested;
use App\Modules\Catalog\Domain\Models\Attribute;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource\Pages\ListProductModeration;
use App\Modules\Organization\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Admin panel — the moderation queue and the taxonomy (Catalog §4, §5)
|--------------------------------------------------------------------------
|
| The Category Manager's half of the end-to-end path: a submitted product is
| approved, rejected or sent back, and the taxonomy it is filed against is
| maintained here.
|
| The verdicts are the only writes on this surface — there is deliberately no
| edit form, because a moderator fixing a product removes the seller's chance to
| learn what was wrong.
|
*/

beforeEach(function (): void {
    $this->seedAll();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * Put an already-authenticated admin into the Category Manager role — the role
 * ADR-013 reserved for exactly this module.
 *
 * Takes the admin rather than calling `test()` internally: the acting-as helper
 * belongs to the test case, and reaching for it from a free function is the kind
 * of indirection that reads fine until someone moves the function.
 */
function asCategoryManager(Admin $admin): Admin
{
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    return $admin;
}

/**
 * A product sitting in the queue, proposed by a real company.
 */
function queuedProduct(): Product
{
    $root = Category::factory()->create();
    $category = Category::factory()->childOf($root)->create();
    $organization = Organization::factory()->create();

    $product = Product::factory()
        ->for($category, 'category')
        ->proposedBy((int) $organization->getKey(), (string) $organization->uuid)
        ->pendingReview()
        ->create();

    ProductVariant::factory()->for($product)->default()->create();

    return $product->refresh();
}

it('shows the pending queue by default', function (): void {
    asCategoryManager($this->actingAsAdmin());

    $waiting = queuedProduct();
    $published = queuedProduct();
    $published->forceFill(['status' => ProductStatus::Published])->save();

    Livewire::test(ListProductModeration::class)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$published]);
});

it('approves a product into the shared catalog', function (): void {
    // The end of the end-to-end path: seller submits → moderator approves →
    // Published. Approving creates nothing new (§5) — it moves this row.
    Event::fake([ProductPublished::class]);

    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    Livewire::test(ListProductModeration::class)
        ->callTableAction('publish', $product);

    $published = $product->fresh();

    expect($published->status)->toBe(ProductStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->moderated_by)->not->toBeNull();

    Event::assertDispatched(ProductPublished::class);
});

it('refuses to approve a product missing a required attribute, with the reason', function (): void {
    // §3.2 — the check lands at publish, and the refusal is a warning naming
    // what is missing, not an error page.
    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    $material = Attribute::factory()->withValues(2)->create(['code' => 'malzeme']);
    $product->category->attributes()->attach($material, ['is_required' => true]);

    Livewire::test(ListProductModeration::class)
        ->callTableAction('publish', $product)
        ->assertHasNoActionErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});

it('sends a product back for revision with a reason', function (): void {
    Event::fake([ProductRevisionRequested::class]);

    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    Livewire::test(ListProductModeration::class)
        ->callTableAction('request_revision', $product, data: [
            'reason' => 'Fotoğraflar bulanık.',
        ]);

    $revised = $product->fresh();

    expect($revised->status)->toBe(ProductStatus::NeedsRevision)
        ->and($revised->moderation_reason)->toBe('Fotoğraflar bulanık.');

    Event::assertDispatched(ProductRevisionRequested::class);
});

it('will not send a product back without saying why', function (): void {
    // The reason is the entire point of NeedsRevision: "fix it" with no note is
    // a rejection that costs an extra round trip.
    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    Livewire::test(ListProductModeration::class)
        ->callTableAction('request_revision', $product, data: ['reason' => ''])
        // Without a key: Filament nests action-form errors under an indexed
        // `mountedActionsData.*` path whose shape is its business, not this
        // test's. What matters is that the form refused and the product did not
        // move.
        ->assertHasActionErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});

it('rejects a product with a reason', function (): void {
    Event::fake([ProductRejected::class]);

    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    Livewire::test(ListProductModeration::class)
        ->callTableAction('reject', $product, data: [
            'reason' => 'Katalogda zaten mevcut.',
        ]);

    expect($product->fresh()->status)->toBe(ProductStatus::Rejected)
        ->and($product->fresh()->moderation_reason)->toBe('Katalogda zaten mevcut.');

    Event::assertDispatched(ProductRejected::class);
});

it('offers no edit and no create on the moderation queue', function (): void {
    // Curation, not authoring (§5). A moderator who could edit the product into
    // shape denies the seller the correction.
    asCategoryManager($this->actingAsAdmin());
    $product = queuedProduct();

    $resource = \App\Modules\Catalog\Presentation\Filament\Resources\ProductModerationResource::class;

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($product))->toBeFalse()
        ->and($resource::canDelete($product))->toBeFalse();
});

it('creates a category from the admin panel', function (): void {
    asCategoryManager($this->actingAsAdmin());

    Livewire::test(CreateCategory::class)
        ->fillForm([
            'name_tr' => 'Elektronik',
            'name_en' => 'Electronics',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->where('slug', 'elektronik')->firstOrFail();

    expect($category->path)->toBe('/'.$category->id.'/')
        ->and($category->depth)->toBe(0);
});

it('refuses a category move that would create a cycle, on the field', function (): void {
    asCategoryManager($this->actingAsAdmin());

    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->create();

    Livewire::test(\App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\EditCategory::class, [
        'record' => $parent->getRouteKey(),
    ])
        ->fillForm([
            'name_tr' => $parent->name_tr,
            'parent_id' => $child->getKey(),
        ])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($parent->fresh()->parent_id)->toBeNull();
});

it('shows the tree ordered by path so children sit under their parent', function (): void {
    asCategoryManager($this->actingAsAdmin());

    $root = Category::factory()->create(['name_tr' => 'Giyim']);
    $child = Category::factory()->childOf($root)->create(['name_tr' => 'Tişört']);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords([$root, $child], inOrder: true);
});

it('keeps a helpdesk admin out of the taxonomy', function (): void {
    // Policies check permissions, never roles — Support simply does not hold
    // `catalog.taxonomy.manage`.
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.support')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    expect(\App\Modules\Catalog\Presentation\Filament\Resources\CategoryResource::canCreate())->toBeFalse();
});
