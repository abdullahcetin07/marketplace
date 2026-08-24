<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\Services\ProductDescriptionTemplate;
use App\Modules\Catalog\Domain\Models\Brand;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Template-generated product descriptions (ADR-088)
|--------------------------------------------------------------------------
|
| ~7,000 sellable products arrived from a supplier feed with no description
| (ADR-074), which keeps every one of them out of the Google Merchant feed
| (ADR-086) — Google rejects an empty description and the rejection counts
| against the account.
|
| Two rules are tested harder than the rest, because both fail expensively and
| silently:
|
|   NOTHING IS INVENTED. Every clause comes from a field the row already carries.
|   A quantity the title does not state is not guessed at — it is left out.
|
|   NOTHING IS CLAIMED. In Turkey a cosmetic or supplement that says it treats or
|   prevents a disease is a regulatory offence, not an exaggeration. Every string
|   the generator can produce is scanned.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A published, sellable product with an empty description.
 *
 * Named for this file: Pest shares ONE global function namespace.
 */
function describableProduct(
    string $title,
    string $rootSlug = 'cilt-bakimi',
    ?string $brandName = 'Marka',
    string $description = '',
): Product {
    $root = Category::query()->firstWhere('slug', $rootSlug)
        ?? Category::factory()->create(['slug' => $rootSlug, 'name_tr' => 'Kök', 'name_en' => 'Root']);

    $leaf = Category::factory()->childOf($root)->create(['name_tr' => 'Alt Kategori', 'name_en' => 'Leaf']);

    return Product::factory()->for($leaf, 'category')->published()->create([
        'title_tr' => $title,
        'title_en' => $title,
        'description_tr' => $description,
        'description_en' => $description,
        'brand_id' => $brandName === null ? null : Brand::factory()->create(['name' => $brandName])->getKey(),
    ]);
}

/*
| NOTE ON SCOPE: the backfill covers every PUBLISHED product, not only the
| sellable ones. `is_sellable` is a cache of current stock and store state rebuilt
| every ten minutes (ADR-079), so scoping to it would make the result depend on
| the minute the command ran. See `DescriptionBackfill`.
*/

function generatedFor(Product $product): string
{
    Artisan::call('catalog:fill-descriptions');

    return (string) $product->refresh()->description_tr;
}

it('fills an empty description and leaves it alone on a second run', function (): void {
    // TWO ROWS MINIMUM: Laravel arms the lazy-loading guard only when a query
    // hydrates more than one, so a single-product fixture would not exercise the
    // eager loads this sweep depends on.
    $first = describableProduct('Nemlendirici Krem 50 ml');
    $second = describableProduct('Temizleme Jeli 200 ml');

    Artisan::call('catalog:fill-descriptions');

    $firstText = (string) $first->refresh()->description_tr;

    expect($firstText)->not->toBe('')
        ->and((string) $second->refresh()->description_tr)->not->toBe('');

    // Idempotent for free: `only_empty` means a second run finds nothing to do,
    // which is also what protects real copy once it starts arriving.
    Artisan::call('catalog:fill-descriptions');

    expect((string) $first->refresh()->description_tr)->toBe($firstText);
});

it('never overwrites a description somebody wrote', function (): void {
    $written = describableProduct('Elle Yazılmış', description: 'Bu açıklamayı bir editör yazdı ve kalmalı.');
    describableProduct('Boş Olan');

    Artisan::call('catalog:fill-descriptions');

    expect((string) $written->refresh()->description_tr)
        ->toBe('Bu açıklamayı bir editör yazdı ve kalmalı.');
});

it('reindexes what it fills, because a raw update would not', function (): void {
    /*
    | THE RULE ADR-074 AND ADR-076 WERE BOTH BUILT ON, asserted through its only
    | observable consequence. A query-builder `->update(['description_tr' => …])`
    | writes the column and fires NO model events, so Scout never hears about it:
    | the row would be right in the table and stale in search — right in the admin
    | list, wrong to every shopper using the search box.
    |
    | Driving `UpdateProductAction` saves the MODEL, and `Searchable` syncs on
    | save. Queueing Scout makes that visible as a dispatched job; nothing else
    | about this path is observable with the null engine.
    */
    describableProduct('Birinci Ürün');
    describableProduct('İkinci Ürün');

    config()->set('scout.queue', true);
    Queue::fake();

    Artisan::call('catalog:fill-descriptions');

    Queue::assertPushed(Laravel\Scout\Jobs\MakeSearchable::class);
});

it('closes a supplement with the wording the regulation requires', function (): void {
    $supplement = describableProduct('Magnezyum 60 Tablet', rootSlug: 'besin-takviyeleri');
    describableProduct('Krem 50 ml');

    $text = generatedFor($supplement);

    expect($text)->toContain('takviye edici gıdadır')
        ->and($text)->toContain('ilaç değildir')
        ->and($text)->toContain('hastalıkların tedavisinde kullanılmaz');
});

it('makes no health claim in anything it can generate', function (): void {
    /*
    | Every family, against every forbidden pattern. The patterns are phrase-shaped
    | rather than bare words on purpose — the mandatory supplement footer contains
    | "hastalıkların TEDAVİSİNDE kullanılmaz", and a scan for `tedavi` would fail
    | the build on the exact sentence the law requires.
    */
    $products = [
        describableProduct('Krem 50 ml', rootSlug: 'cilt-bakimi'),
        describableProduct('Vitamin 60 Tablet', rootSlug: 'besin-takviyeleri'),
        describableProduct('Termometre', rootSlug: 'saglik-ve-medikal'),
        describableProduct('Kedi Maması 400 gr', rootSlug: 'pet-shop'),
        describableProduct('Bebek Devam Sütü 400 gr', rootSlug: 'anne-ve-bebek'),
    ];

    Artisan::call('catalog:fill-descriptions');

    /** @var array<int, string> $patterns */
    $patterns = (array) config('product_descriptions.forbidden_claims');

    foreach ($products as $product) {
        $text = (string) $product->refresh()->description_tr;

        expect($text)->not->toBe('');

        foreach ($patterns as $pattern) {
            expect(preg_match($pattern, $text))->toBe(0, $pattern.' matched: '.$text);
        }
    }
});

it('reads the tokens a title states and stays silent about the ones it does not', function (): void {
    $full = describableProduct('Güneş Kremi SPF 50 200 ml');
    $bare = describableProduct('Ruj Chinchilla');

    Artisan::call('catalog:fill-descriptions');

    expect((string) $full->refresh()->description_tr)
        ->toContain('SPF 50')
        ->toContain('Net miktarı 200 ml')
        ->toContain('Krem formundadır');

    // No tokens at all: the sentence must still read as a sentence, not as a
    // template with holes in it.
    $bareText = (string) $bare->refresh()->description_tr;

    expect($bareText)->not->toContain('  ')
        ->and($bareText)->not->toContain('SPF')
        ->and($bareText)->not->toContain('Net miktarı')
        ->and($bareText)->toContain('kategorisinde yer alan bir');
});

it('reads a quantity however the supplier capitalised the unit', function (): void {
    /*
    | THE SAME CATALOGUE WRITES "500 Gr", "200 ML", "1 Lt" AND "50 ml". The first
    | pattern listed a few spellings literally and produced no quantity sentence
    | for any of the others — silently, on thousands of rows.
    */
    $upper = describableProduct('Portakal Sabunu 500 Gr');
    $mixed = describableProduct('Tonik 200 ML');

    Artisan::call('catalog:fill-descriptions');

    expect((string) $upper->refresh()->description_tr)->toContain('Net miktarı 500 gr')
        ->and((string) $mixed->refresh()->description_tr)->toContain('Net miktarı 200 ml');
});

it('names a multipack in Turkish that parses', function (): void {
    // "5'li pakettir." — the -li suffix already means "of N", so the earlier
    // "Paket içeriği 5'li adettir" was both redundant and ungrammatical.
    $pack = describableProduct("Portakal Sabunu 5'li");
    describableProduct('Tekli Ürün');

    $text = generatedFor($pack);

    expect($text)->toContain("5'li pakettir.")
        ->and($text)->not->toContain('adettir');
});

it('states no quantity it cannot vouch for', function (): void {
    /*
    | BOTH OF THESE PRODUCED A WRONG NUMBER ON THE LIVE CATALOGUE.
    |
    | "12 x 5 ml" is 60 ml and the pattern sees the 5; a milligram figure in a
    | supplement title is the dose per capsule, not the contents — "Tru Niagen
    | 300mg 30 Kapsül" became "Net miktarı 300 mg". Silent beats wrong.
    */
    $multipack = describableProduct('Ampul 12 x 5 ml');
    $dose = describableProduct('Takviye 300mg 30 Kapsül', rootSlug: 'besin-takviyeleri');

    Artisan::call('catalog:fill-descriptions');

    expect((string) $multipack->refresh()->description_tr)->not->toContain('Net miktarı')
        ->and((string) $dose->refresh()->description_tr)->not->toContain('Net miktarı');
});

it('will not tell a parent that infant formula is for external use', function (): void {
    /*
    | `anne-ve-bebek` holds baby shampoo AND infant formula — a cosmetic and a
    | food. Mapped wholesale to `cosmetic` it produced, on the live catalogue,
    | "SMA Comfort 3 Devam Sütü … Haricen kullanım içindir."
    */
    $formula = describableProduct('Devam Sütü 400 gr', rootSlug: 'anne-ve-bebek');
    describableProduct('Krem 50 ml');

    $text = generatedFor($formula);

    expect($text)->not->toContain('Haricen kullanım')
        ->and($text)->toContain('kategorisinde yer alan bir üründür');
});

it('skips a product it cannot describe rather than inventing one', function (): void {
    $describable = describableProduct('Anlatılabilir Ürün');
    $titleless = describableProduct('Başlıksız');
    $titleless->forceFill(['title_tr' => '', 'title_en' => ''])->saveQuietly();

    Artisan::call('catalog:fill-descriptions');

    expect((string) $titleless->refresh()->description_tr)->toBe('')
        ->and((string) $describable->refresh()->description_tr)->not->toBe('');
});

it('describes a product with no brand, because half the catalogue has none', function (): void {
    /*
    | 3,359 of the 7,025 empty-description products carry no brand. Skipping them
    | as "missing a critical field" would leave the feed half empty for a sentence
    | that is perfectly true without a manufacturer's name in it.
    */
    $brandless = describableProduct('Markasız Ürün 100 ml', brandName: null);
    describableProduct('Markalı Ürün');

    $text = generatedFor($brandless);

    expect($text)->toStartWith('Markasız Ürün 100 ml,')
        ->and($text)->toContain('Net miktarı 100 ml');
});

it('does not repeat a brand the title already opens with', function (): void {
    $template = app(ProductDescriptionTemplate::class);

    $product = describableProduct('Bioderma Sensibio H2O 250 ml', brandName: 'Bioderma');
    describableProduct('İkinci');

    $text = (string) $template->for($product->refresh(), ['Cilt Bakımı'], 'cilt-bakimi');

    // Supplier titles usually lead with the brand; prefixing it again would read
    // "Bioderma Bioderma Sensibio H2O" seven thousand times.
    expect($text)->toStartWith('Bioderma Sensibio H2O')
        ->and($text)->not->toContain('Bioderma Bioderma');
});

it('reads a form word through its Turkish suffix without matching a lookalike', function (): void {
    /*
    | TURKISH AGGLUTINATES AND THE STEM NEVER APPEARS BARE. Titles say "Kremi",
    | "Jeli", "Şampuanı", "Macunu" — a whole-word match found none of them, and
    | the generator silently produced descriptions with no form sentence at all.
    |
    | The suffix set is CLOSED, which is the other half: an open one would have
    | `jel` match `jelatin` and `toz` match `tozluk`.
    */
    $reader = new ReflectionMethod(ProductDescriptionTemplate::class, 'formIn');
    $reader->setAccessible(true);
    $template = app(ProductDescriptionTemplate::class);

    $cases = [
        'Nemlendirici Kremi' => 'krem',
        'Duş Jeli' => 'jel',
        'Saç Şampuanı' => 'şampuan',
        'Diş Macunu' => 'macun',
        'Maskesi' => 'maske',
        'Kremler' => 'krem',
        // Lookalikes: a gaiter is not a powder, and gelatine is not a gel.
        'Tozluk' => null,
        'Jelatin' => null,
        'Ruj Chinchilla' => null,
    ];

    foreach ($cases as $title => $expected) {
        expect($reader->invoke($template, $title))->toBe($expected, $title);
    }
});
