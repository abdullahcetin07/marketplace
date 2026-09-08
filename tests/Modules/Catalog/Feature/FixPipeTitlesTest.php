<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| catalog:fix-pipe-titles
|--------------------------------------------------------------------------
|
| The supplier file turned commas into pipes: `7|5 ml` is a decimal comma and
| `Skin | Hair & Nails` is the product's own branding. Telling those apart is
| the command's only real decision, and getting it wrong either leaves the
| catalogue reading `7|5 ml` or rewrites a brand name nobody asked it to touch.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function pipedProduct(string $title, ?string $description = null): Product
{
    return Product::factory()
        ->for(Category::factory()->childOf(Category::factory()->create())->create(), 'category')
        ->published()
        ->create(['title_tr' => $title, 'description_tr' => $description ?? 'Açıklama.']);
}

it('turns a decimal pipe into a comma through the authoring action', function (): void {
    /*
    | The ADR-074/088 rule: a query-builder update would set the column and fire
    | nothing, leaving the row right in the table and stale in search and both
    | feeds — asserted with the model's own saved event, which is what Scout hears.
    */
    $product = pipedProduct('La Roche Posay Cicaplast Levres 7|5 ml');

    Event::fake(['eloquent.saved: '.Product::class]);

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])->assertSuccessful();

    expect($product->fresh()->title_tr)->toBe('La Roche Posay Cicaplast Levres 7,5 ml');

    Event::assertDispatched('eloquent.saved: '.Product::class);
});

it('leaves a pipe a human put there alone', function (): void {
    // ` | ` with a space in front is how the product is branded, not a lost comma.
    $product = pipedProduct('Viapecia Hair | Nail & Skin 60 Kapsül');

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])
        ->expectsOutputToContain('dokunulmadı')
        ->assertSuccessful();

    expect($product->fresh()->title_tr)->toBe('Viapecia Hair | Nail & Skin 60 Kapsül');
});

it('fixes the welded pipe and keeps the spaced one in the same title', function (): void {
    // Which is why the rule is positional rather than a skip-list.
    $product = pipedProduct('CeceCap Skin | Hair & Nails 7|5 ml');

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])->assertSuccessful();

    expect($product->fresh()->title_tr)->toBe('CeceCap Skin | Hair & Nails 7,5 ml');
});

it('repairs the description that quotes the same broken title', function (): void {
    $product = pipedProduct(
        'Nivea Strawberry Lip Stick 4|8gr',
        'Nivea Strawberry Lip Stick, 4|8gr ağırlığında bir dudak bakım kremidir.',
    );

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])->assertSuccessful();

    expect($product->fresh()->description_tr)
        ->toBe('Nivea Strawberry Lip Stick, 4,8gr ağırlığında bir dudak bakım kremidir.');
});

it('does not move the slug a search engine already indexed', function (): void {
    $product = pipedProduct('Herevin Biber Öğütücü 16|5 cm');
    $slug = $product->slug;

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])->assertSuccessful();

    expect($product->fresh()->slug)->toBe($slug);
});

it('reports without writing by default', function (): void {
    $product = pipedProduct('Akay Kelebek Kase No:1 0|35 lt');

    $this->artisan('catalog:fix-pipe-titles')
        ->expectsOutputToContain('düzeltilecek')
        ->assertSuccessful();

    expect($product->fresh()->title_tr)->toBe('Akay Kelebek Kase No:1 0|35 lt');
});

it('changes nothing on a second run', function (): void {
    pipedProduct('Plastart Saklama Kabı 1|5 lt');

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])->assertSuccessful();

    $this->artisan('catalog:fix-pipe-titles', ['--apply' => true])
        ->expectsOutputToContain('Toplam 0')
        ->assertSuccessful();
});
