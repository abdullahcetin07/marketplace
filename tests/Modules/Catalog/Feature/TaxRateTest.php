<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Models\Admin;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Catalog\Domain\Models\TaxRate;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages\CreateTaxRate;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages\EditTaxRate;
use App\Modules\Catalog\Presentation\Filament\Resources\TaxRateResource\Pages\ListTaxRates;
use App\Modules\Catalog\Presentation\Filament\Seller\Resources\ProductResource;
use Database\Modules\Catalog\Seeders\TaxRateSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| KDV brackets — the Catalog addition Order drove (ADR-056, Catalog.md §2.4)
|--------------------------------------------------------------------------
|
| A tax bracket is the one thing about a product that is neither editorial nor
| commercial: it is what the LAW says the goods are. So the interesting assertions
| are about who owns the number and how it is allowed to move:
|
|  1. IT IS A TABLE, NOT AN ENUM. Brackets change by government decision (%18 →
|     %20 in July 2023, with days of notice), so an operator adds and withdraws
|     them without a deploy.
|  2. IT DOES NOT BREACH ADR-037. A bracket classifies goods; there is still no
|     price and no stock in the Catalog, and `CatalogBoundaryTest` polices the
|     contract's method names.
|  3. THE RATE NEVER TOUCHES A FLOAT. The column is DECIMAL and the contract hands
|     back a decimal STRING, so the caller can scale it to an integer and extract
|     KDV without binary rounding anywhere near money.
|  4. A WITHDRAWN BRACKET KEEPS ANSWERING. Deactivating hides it from the
|     authoring picker; products already filed under it are still lawfully
|     taxable, which is why the read does not filter on `is_active`.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A leaf category products can attach to.
 *
 * Named for this file because Pest shares ONE global function namespace across
 * the whole suite.
 */
function taxableCategory(): Category
{
    return Category::factory()->childOf(Category::factory()->create())->create();
}

/**
 * @return array{product: Product, rate: TaxRate}
 */
function productOnBracket(string $ratio = '0.2000'): array
{
    $rate = TaxRate::factory()->rate($ratio)->create();
    $product = Product::factory()->for(taxableCategory(), 'category')->create(['tax_rate_id' => $rate->getKey()]);

    return ['product' => $product, 'rate' => $rate];
}

/*
|--------------------------------------------------------------------------
| The bracket itself
|--------------------------------------------------------------------------
*/

it('stores the rate as a ratio at four decimals, whatever the input scale', function (): void {
    // Saved by hand at scale 1; read back at the column's scale. The cast is what
    // makes "0.2" and "0.2000" the same rate rather than two.
    $rate = TaxRate::factory()->create(['rate' => '0.2']);

    expect($rate->fresh()->rate)->toBe('0.2000');
});

it('scales the rate to an integer so the tax maths never sees a float', function (): void {
    expect(TaxRate::factory()->rate('0.2000')->create()->scaledRate())->toBe(2000)
        ->and(TaxRate::factory()->rate('0.1000')->create()->scaledRate())->toBe(1000)
        ->and(TaxRate::factory()->rate('0.0100')->create()->scaledRate())->toBe(100)
        ->and(TaxRate::factory()->rate('0.0000')->create()->scaledRate())->toBe(0)
        // The scale is a named constant precisely so this relationship holds.
        ->and(TaxRate::SCALE)->toBe(10_000);
});

it('renders a percentage a human recognises', function (): void {
    expect(TaxRate::factory()->rate('0.2000')->create()->percentLabel())->toBe('%20')
        ->and(TaxRate::factory()->rate('0.0100')->create()->percentLabel())->toBe('%1')
        ->and(TaxRate::factory()->rate('0.0000')->create()->percentLabel())->toBe('%0')
        // A fractional bracket still reads honestly if one is ever invented.
        ->and(TaxRate::factory()->rate('0.0850')->create()->percentLabel())->toBe('%8,5');
});

it('records who changed a rate, because a tax inspection asks', function (): void {
    $rate = TaxRate::factory()->rate('0.1000')->create();

    $rate->update(['rate' => '0.2000']);

    // The whole reason this model is Auditable and needs no write action: a rate
    // change re-prices the tax on everything sold under the bracket from now on.
    expect($rate->audits()->count())->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| The seeder, and the backfill that makes the nullable column safe
|--------------------------------------------------------------------------
*/

it('seeds the Turkish brackets and is safe to run twice', function (): void {
    (new TaxRateSeeder)->run();
    (new TaxRateSeeder)->run();

    expect(TaxRate::query()->count())->toBe(4)
        ->and(TaxRate::query()->pluck('rate')->sort()->values()->all())
        ->toBe(['0.0000', '0.0100', '0.1000', '0.2000']);
});

it('does not overwrite an operator’s rate on the next deploy', function (): void {
    (new TaxRateSeeder)->run();

    // A government moves %20 to %22 and an operator edits the row.
    TaxRate::query()->where('code', TaxRateSeeder::DEFAULT_CODE)->update(['rate' => '0.2200']);

    (new TaxRateSeeder)->run();

    /*
     * `firstOrCreate`, not `updateOrCreate`. Re-seeding a "correction" here would
     * silently revert a compliance change — the one failure mode of a seeder that
     * owns legally-meaningful numbers.
     */
    expect(TaxRate::query()->where('code', TaxRateSeeder::DEFAULT_CODE)->value('rate'))->toBe('0.2200');
});

it('backfills products that predate the column', function (): void {
    $product = Product::factory()->for(taxableCategory(), 'category')->create();

    // The state the migration leaves behind: nullable column, no bracket yet,
    // because a schema migration cannot point rows at operator-owned lookup data.
    Product::query()->whereKey($product->getKey())->update(['tax_rate_id' => null]);

    (new TaxRateSeeder)->run();

    expect($product->fresh()->tax_rate_id)
        ->toBe(TaxRate::query()->where('code', TaxRateSeeder::DEFAULT_CODE)->value('id'));
});

it('backfills to the GENERAL bracket, not the cheapest one', function (): void {
    $product = Product::factory()->for(taxableCategory(), 'category')->create();
    Product::query()->whereKey($product->getKey())->update(['tax_rate_id' => null]);

    (new TaxRateSeeder)->run();

    // Guessing low would under-collect KDV on every backfilled product and make
    // the platform's problem the tax office's.
    expect($product->fresh()->taxRate?->rate)->toBe('0.2000');
});

it('leaves an already-classified product alone', function (): void {
    ['product' => $product, 'rate' => $rate] = productOnBracket('0.0100');

    (new TaxRateSeeder)->run();

    expect($product->fresh()->tax_rate_id)->toBe($rate->getKey());
});

/*
|--------------------------------------------------------------------------
| The Core read port — how Order learns the rate without importing Catalog
|--------------------------------------------------------------------------
*/

it('answers a product’s rate as a decimal string', function (): void {
    ['product' => $product] = productOnBracket('0.1000');

    $rate = app(CatalogQueryContract::class)->taxRateForProduct($product->uuid);

    // A STRING, deliberately: the column is DECIMAL because a rate multiplied
    // against a large total loses real money to binary rounding, and returning a
    // float here would give back exactly what the column type bought.
    expect($rate)->toBe('0.1000')
        ->and($rate)->toBeString();
});

it('answers null for a product that does not exist', function (): void {
    expect(app(CatalogQueryContract::class)->taxRateForProduct('yok-boyle-bir-urun'))->toBeNull();
});

it('answers null for a product with no bracket rather than guessing one', function (): void {
    $product = Product::factory()->for(taxableCategory(), 'category')->create();
    Product::query()->whereKey($product->getKey())->update(['tax_rate_id' => null]);

    // Silence beats a default: a checkout must fail loudly rather than charge a
    // rate nobody classified.
    expect(app(CatalogQueryContract::class)->taxRateForProduct($product->uuid))->toBeNull();
});

it('keeps answering for a product on a WITHDRAWN bracket', function (): void {
    $rate = TaxRate::factory()->rate('0.0800')->inactive()->create();
    $product = Product::factory()->for(taxableCategory(), 'category')
        ->create(['tax_rate_id' => $rate->getKey()]);

    /*
     * The case that decides whether the read filters on `is_active`, and it must
     * not: a repealed bracket still classifies the goods already filed under it,
     * and refusing to answer would fail checkout for every product an operator has
     * not yet re-classified.
     */
    expect(app(CatalogQueryContract::class)->taxRateForProduct($product->uuid))->toBe('0.0800');
});

/*
|--------------------------------------------------------------------------
| Authoring and moderation
|--------------------------------------------------------------------------
*/

it('offers only active brackets on the authoring form, general first', function (): void {
    (new TaxRateSeeder)->run();
    $withdrawn = TaxRate::factory()->rate('0.0800')->inactive()->create();

    $options = ProductResource::taxRateOptions();

    expect(array_key_first($options))
        ->toBe(TaxRate::query()->where('code', TaxRateSeeder::DEFAULT_CODE)->value('id'))
        ->and($options)->not->toHaveKey($withdrawn->getKey());
});

it('refuses to submit a product with no bracket', function (): void {
    $product = Product::factory()->for(taxableCategory(), 'category')->create();
    ProductVariant::factory()->for($product)->create();
    Product::query()->whereKey($product->getKey())->update(['tax_rate_id' => null]);

    /*
     * The backstop under the form's `required`. Without a rate, checkout has
     * nothing to extract KDV with, so approving it would put a product in the
     * catalog that cannot lawfully be sold — the same class of refusal as the
     * one-variant rule.
     */
    expect(fn () => app(\App\Modules\Catalog\Application\Actions\SubmitProductForReviewAction::class)
        ->run($product->fresh()))
        ->toThrow(CatalogException::class);
});

it('submits a product that has one', function (): void {
    ['product' => $product] = productOnBracket();
    ProductVariant::factory()->for($product)->create();

    $submitted = app(\App\Modules\Catalog\Application\Actions\SubmitProductForReviewAction::class)->run($product);

    expect($submitted->status)->toBe(\App\Modules\Catalog\Domain\Enums\ProductStatus::PendingReview);
});

/*
|--------------------------------------------------------------------------
| The admin resource — the reason a table beats an enum
|--------------------------------------------------------------------------
*/

it('lets the Category Manager add a bracket without a deploy', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    /** @var Admin $admin */
    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    Livewire::test(CreateTaxRate::class)
        // ENTERED AS A PERCENTAGE — asking a human for "0.22" to mean %22 is how
        // a bracket eventually gets saved as 22.0000 and multiplies every total
        // by twenty-three.
        ->fillForm(['code' => 'kdv-22', 'name' => 'KDV %22', 'rate' => '22', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(TaxRate::query()->where('code', 'kdv-22')->value('rate'))->toBe('0.2200');
});

it('shows an existing rate back as a percentage', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    $rate = TaxRate::factory()->rate('0.1000')->create();

    // Both directions of the conversion, in one place, so the pair cannot drift.
    Livewire::test(EditTaxRate::class, ['record' => $rate->getRouteKey()])
        ->assertFormSet(['rate' => '10']);
});

it('never lets anybody delete a bracket', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAsAdmin();

    $rate = TaxRate::factory()->create();

    // Products reference it, the FK restricts, and an order line snapshots the
    // rate anyway — so deleting buys nothing and can orphan a product mid-review.
    expect(TaxRateResource::canDelete($rate))->toBeFalse()
        ->and(TaxRateResource::canDeleteAny())->toBeFalse()
        ->and(array_keys(TaxRateResource::getPages()))->not->toContain('view');
});

it('lets any admin READ the brackets but only a curator change one', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $support = $this->actingAsAdmin();
    $support->syncRoles([config('marketplace.roles.support')]);
    $support->refresh()->loadMissing('roles.permissions', 'permissions');

    $rate = TaxRate::factory()->create();

    /*
     * The split `BrandResource` and the taxonomy already draw, borrowed rather
     * than re-invented: brackets are reference data, so a helpdesk answering
     * "why is the KDV on my invoice %20" may read them — and classifying goods
     * for tax is the Category Manager's job, so Support may not write one.
     */
    expect(TaxRateResource::canViewAny())->toBeTrue()
        ->and(TaxRateResource::canCreate())->toBeFalse()
        ->and(TaxRateResource::canEdit($rate))->toBeFalse();
});

it('shows how much of the catalog a rate change would touch', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $admin = $this->actingAsAdmin();
    $admin->syncRoles([config('marketplace.roles.category_manager')]);
    $admin->refresh()->loadMissing('roles.permissions', 'permissions');

    ['rate' => $rate] = productOnBracket('0.0100');
    Product::factory()->for(taxableCategory(), 'category')->create(['tax_rate_id' => $rate->getKey()]);

    /*
     * The number an operator wants before editing a live tax rate.
     *
     * The count is loaded onto the record the assertion passes in, because
     * Filament resolves a column's state against THAT instance rather than
     * re-reading it from the table's query — an in-memory model has no
     * `products_count` attribute for `counts()` to render.
     */
    Livewire::test(ListTaxRates::class)
        ->assertCanSeeTableRecords([$rate])
        ->assertTableColumnStateSet('products_count', 2, $rate->loadCount('products'));
});
