<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| catalog:import-descriptions (BUILD_PRODUCT_DESCRIPTION_ENRICHMENT.md)
|--------------------------------------------------------------------------
|
| Approved copy going into the catalogue by GTIN. Three things can go wrong
| here and each one is worse than the missing description it replaces: writing
| a health claim (Turkish cosmetics law, not taste), destroying somebody's
| hand-written copy, and writing the column behind the authoring action's back
| so search and both feeds go stale.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function pilotFile(string $body, string $gtin = '8690000000017', string $title = 'Test Ürünü — 40 ml'): string
{
    $path = sys_get_temp_dir().'/pilot-'.uniqid().'.md';

    file_put_contents($path, <<<MD
        # Pilot

        ---

        ### 1. {$title}
        `GTIN {$gtin}`
        {$body}

        ---
        MD);

    return $path;
}

function importableProduct(string $gtin = '8690000000017', ?string $description = null): Product
{
    $product = Product::factory()
        ->for(Category::factory()->childOf(Category::factory()->create())->create(), 'category')
        ->published()
        ->create([
            'gtin' => $gtin,
            'description_tr' => $description ?? 'Test Ürünü, Bakım kategorisinde yer alan bir üründür.',
        ]);

    ProductVariant::factory()->for($product)->create();

    return $product;
}

it('writes approved copy through the authoring action', function (): void {
    /*
    | The ADR-074/076/088 rule. A query-builder update would set the column and
    | fire nothing, so the row would be right in the table and stale in search,
    | the storefront and both feeds — asserted with the model's own saved event,
    | which is what Scout listens to.
    */
    $product = importableProduct();
    importableProduct('8690000000024');

    $body = "Test Ürünü 40 ml, kuru ciltler için nemlendirici bakım kremidir.\n- Cildi nemlendirmeye yardımcı olur.\n\nKullanım: Sabah ve akşam uygulayın.";

    Event::fake(['eloquent.saved: '.Product::class]);

    $this->artisan('catalog:import-descriptions', ['file' => pilotFile($body), '--apply' => true])
        ->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe($body);

    Event::assertDispatched('eloquent.saved: '.Product::class);
});

it('reports without writing by default', function (): void {
    $product = importableProduct();
    importableProduct('8690000000024');

    $before = $product->description_tr;

    $this->artisan('catalog:import-descriptions', ['file' => pilotFile('Yeni metin, kuru ciltler için.')])
        ->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe($before);
});

it('refuses a health claim even though a human approved it', function (): void {
    /*
    | The scan is not a second opinion on the copywriter — it is the reason the
    | pattern list exists (ADR-088). "İyileştirir" is a medical claim on a
    | cosmetic, and a confident sentence reads fine until a regulator reads it.
    | The command exits non-zero so a batch cannot be green about it.
    */
    $product = importableProduct();
    importableProduct('8690000000024');

    $file = pilotFile('Test Ürünü 40 ml, cilt çatlaklarını iyileştirir ve tamamen geçirir.');

    $this->artisan('catalog:import-descriptions', ['file' => $file, '--apply' => true])
        ->assertFailed();

    expect($product->fresh()->description_tr)->toContain('kategorisinde yer alan');
});

it('lets the mandatory supplement disclaimer through', function (): void {
    /*
    | The other half of the same rule: the patterns are claim-SHAPED, so
    | "hastalıkların tedavisinde kullanılmaz" — the sentence the regulation
    | REQUIRES — must not be mistaken for the claim it negates.
    */
    $product = importableProduct();
    importableProduct('8690000000024');

    $body = "Test Ürünü 40 ml, günlük kullanıma uygun takviye edici gıdadır.\n\nTakviye edici gıdadır, ilaç değildir; hastalıkların tedavisinde kullanılmaz.";

    $this->artisan('catalog:import-descriptions', ['file' => pilotFile($body), '--apply' => true])
        ->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe($body);
});

it('will not overwrite copy that is not the generated template', function (): void {
    /*
    | After ADR-088 every product HAS a description, so "is it empty?" stopped
    | answering "would I be destroying somebody's work?". The template's own
    | sentence is the marker; anything else needs to be asked for twice.
    */
    $product = importableProduct(description: 'Elle yazılmış, özenli bir açıklama metni.');
    importableProduct('8690000000024');

    $file = pilotFile('Otomatik metin, kuru ciltler için.');

    $this->artisan('catalog:import-descriptions', ['file' => $file, '--apply' => true])->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe('Elle yazılmış, özenli bir açıklama metni.');

    $this->artisan('catalog:import-descriptions', ['file' => $file, '--apply' => true, '--force' => true])
        ->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe('Otomatik metin, kuru ciltler için.');
});

it('matches a variant barcode as well as the product gtin', function (): void {
    // The number on the box is a variant barcode; the number the importer wrote
    // is the product gtin. Approved copy arrives with whichever one was to hand.
    $product = importableProduct('8690000000031');
    $product->variants()->first()->update(['barcode' => '8690000000048']);
    importableProduct('8690000000024');

    $this->artisan('catalog:import-descriptions', [
        'file' => pilotFile('Barkodla eşleşen metin, kuru ciltler için.', '8690000000048'),
        '--apply' => true,
    ])->assertSuccessful();

    expect($product->fresh()->description_tr)->toBe('Barkodla eşleşen metin, kuru ciltler için.');
});

it('changes nothing on a second run', function (): void {
    importableProduct();
    importableProduct('8690000000024');

    $file = pilotFile('Aynı metin, kuru ciltler için.');

    $this->artisan('catalog:import-descriptions', ['file' => $file, '--apply' => true])->assertSuccessful();

    $this->artisan('catalog:import-descriptions', ['file' => $file, '--apply' => true])
        ->expectsOutputToContain('aynı 1')
        ->assertSuccessful();
});
