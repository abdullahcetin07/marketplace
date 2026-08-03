<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Actions\CreateBrandAction;
use App\Modules\Catalog\Domain\Contracts\SlugRegistryContract;
use App\Modules\Catalog\Domain\DTOs\CreateBrandDTO;
use App\Modules\Catalog\Domain\Enums\SluggableType;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\Slug;

/*
|--------------------------------------------------------------------------
| The global slug namespace (ADR-059)
|--------------------------------------------------------------------------
|
| The flat URL scheme puts product, category, brand AND the storefront's own pages
| at the root, so every one of them competes for the same names. This file is the
| proof that the competition is resolved rather than raced:
|
|   GLOBAL          a brand cannot take a category's slug, or the other way round
|   RESERVED        nothing can shadow /sepet, /hesap, /api …
|   TURKISH         "Cilt Bakımı" → cilt-bakimi, not cilt-bak-m-
|   STABLE          renaming an entity does NOT move its URL
|   ALIASED         when a slug does change, the old one still resolves, for a 301
|   AUTOMATIC       a slug is registered on save, from every path there is
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/** Named for this file because Pest shares ONE global function namespace. */
function registry(): SlugRegistryContract
{
    return app(SlugRegistryContract::class);
}

it('folds Turkish characters the way a URL needs', function (): void {
    /*
     * `Str::slug` already transliterates İ/ı/ş/ğ/ü/ö/ç correctly, so there is no
     * hand-rolled slugifier in this codebase — but that is a claim, and an
     * unverified claim about URL generation is how a catalogue ends up with
     * `cilt-bak-m-` indexed by Google.
     */
    expect(registry()->issue('Cilt Bakımı', SluggableType::Category))->toBe('cilt-bakimi')
        ->and(registry()->issue('İstanbul Şaşırtıcı Ürün', SluggableType::Product))->toBe('istanbul-sasirtici-urun')
        ->and(registry()->issue('Avène Cicalfate+ 40 ml', SluggableType::Product))->toBe('avene-cicalfate-40-ml')
        ->and(registry()->issue('ÇOK GÜZEL', SluggableType::Brand))->toBe('cok-guzel');
});

it('falls back to the type name when a title slugs to nothing', function (): void {
    // "!!!" and "———" are real product titles somewhere. An empty slug would put
    // the entity at the site root, which is a page that already exists.
    expect(registry()->issue('!!!', SluggableType::Product))->toBe('product')
        ->and(registry()->issue('———', SluggableType::Brand))->toBe('brand');
});

it('refuses a slug that would shadow one of the storefront’s own pages', function (): void {
    /*
     * A product called "Sepet" slugs to `sepet` and the storefront's static route
     * wins — so the product is not broken, it is UNREACHABLE, and nobody reports
     * a bug they cannot describe. Suffixed rather than rejected: the seller's
     * listing is fine, it just cannot live at the basket's address.
     */
    expect(registry()->issue('Sepet', SluggableType::Product))->toBe('sepet-2')
        ->and(registry()->issue('api', SluggableType::Category))->toBe('api-2')
        ->and(registry()->isReserved('hesap'))->toBeTrue()
        ->and(registry()->isReserved('bioderma'))->toBeFalse();
});

it('keeps one namespace across products, categories and brands', function (): void {
    $category = Category::factory()->create(['slug' => 'dermokozmetik']);

    // THE WHOLE COST OF THE FLAT SCHEME, in one assertion. Under a prefixed
    // scheme both could be "dermokozmetik" because /kategori/... and /marka/...
    // are different URLs. Here they are the same URL.
    $brand = app(CreateBrandAction::class)->run(new CreateBrandDTO(name: 'Dermokozmetik'));

    expect($brand->slug)->toBe('dermokozmetik-2')
        ->and($category->fresh()->slug)->toBe('dermokozmetik');

    expect(Slug::query()->where('slug', 'dermokozmetik')->value('sluggable_type'))
        ->toBe(SluggableType::Category);
});

it('registers a slug on save, from any path that writes one', function (): void {
    // A model hook rather than a call in each action, because the failure mode of
    // forgetting one is silent: an entity with no public address at all.
    $category = Category::factory()->create(['slug' => 'yeni-kategori']);
    $brand = Brand::factory()->create(['slug' => 'yeni-marka']);
    $product = Product::factory()->for($category, 'category')->create(['slug' => 'yeni-urun']);

    foreach ([
        ['yeni-kategori', SluggableType::Category, $category->getKey()],
        ['yeni-marka', SluggableType::Brand, $brand->getKey()],
        ['yeni-urun', SluggableType::Product, $product->getKey()],
    ] as [$slug, $type, $id]) {
        $row = Slug::query()->where('slug', $slug)->sole();

        expect($row->sluggable_type)->toBe($type)
            ->and($row->sluggable_id)->toBe((int) $id)
            ->and($row->is_canonical)->toBeTrue();
    }
});

it('does NOT move a URL when the entity is renamed', function (): void {
    $category = Category::factory()->create(['name_tr' => 'Cilt Bakımı', 'slug' => 'cilt-bakimi']);

    // The rename that must not break a link. A URL that silently moves is a URL
    // that 404s for everyone who ever shared it.
    $category->update(['name_tr' => 'Cilt ve Vücut Bakımı']);

    expect($category->fresh()->slug)->toBe('cilt-bakimi')
        ->and(Slug::query()->where('slug', 'cilt-bakimi')->value('is_canonical'))->toBeTruthy()
        ->and(Slug::query()->count())->toBe(1);
});

it('keeps the old address alive as an alias when a slug deliberately changes', function (): void {
    $category = Category::factory()->create(['slug' => 'eski-adres']);

    // A deliberate change — the only way a slug moves.
    $category->update(['slug' => 'yeni-adres']);

    $old = registry()->resolve('eski-adres');
    $new = registry()->resolve('yeni-adres');

    /*
     * DEMOTED, NOT DELETED. The old row still resolves and reports where the
     * entity moved to, which is what lets the storefront answer 301 instead of
     * 404 — and what stops the platform serving two URLs for one page forever.
     */
    expect($old)->not->toBeNull()
        ->and($old->canonicalSlug)->toBe('yeni-adres')
        ->and($old->isAlias())->toBeTrue()
        ->and($new->isAlias())->toBeFalse()
        ->and(Slug::query()->count())->toBe(2);
});

it('lets an entity re-issue over its own slug without suffixing it', function (): void {
    $category = Category::factory()->create(['slug' => 'ayni-slug']);

    // Otherwise every update that recomputed a slug would walk it to
    // `ayni-slug-2`, `-3`, `-4` — colliding with nothing but itself.
    expect(registry()->issue('ayni-slug', SluggableType::Category, (int) $category->getKey()))
        ->toBe('ayni-slug');

    expect(registry()->issue('ayni-slug', SluggableType::Category))->toBe('ayni-slug-2');
});

it('releases a slug on a hard delete but parks it on a soft one', function (): void {
    $category = Category::factory()->create(['slug' => 'gecici-kategori']);
    $product = Product::factory()->for($category, 'category')->create(['slug' => 'gecici-urun']);

    // Soft-deleted: the row survives, so the slug stays parked and comes back
    // with the product if it is restored. It stops RESOLVING on its own, because
    // the registry loads the model and the default scope hides it.
    $product->delete();

    expect(Slug::query()->where('slug', 'gecici-urun')->exists())->toBeTrue()
        ->and(registry()->resolve('gecici-urun'))->toBeNull();

    // Really gone: the name has to be reusable, or it is reserved forever by
    // something that no longer exists. A category of its own, because the one
    // above still has a (soft-deleted) product pointing at it and the foreign key
    // is doing its job.
    $empty = Category::factory()->create(['slug' => 'bos-kategori']);
    $empty->delete();

    expect(Slug::query()->where('slug', 'bos-kategori')->exists())->toBeFalse();
});

it('resolves nothing for a slug that was never issued', function (): void {
    expect(registry()->resolve('hicbir-sey'))->toBeNull();
});
