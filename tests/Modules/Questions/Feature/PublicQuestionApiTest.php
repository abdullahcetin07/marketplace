<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| What a stranger reads (ADR-070)
|--------------------------------------------------------------------------
|
| **AN UNANSWERED QUESTION IS PRIVATE TO THREE PEOPLE** — the target seller, an
| admin, and the asker. Publishing one early would put a shopper's words on a
| product page before the merchant they were aimed at had seen them, which is the
| opposite of what "Satıcıya Sor" promises.
|
| And the asker is a masked name and nothing else: a customer uuid here would let
| anyone rebuild one person's browsing interests from public data.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published product and a live shop.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, store: Store}
 */
function questionedProductFixture(string $storeName = 'Deniz Elektronik'): array
{
    $category = Category::factory()->childOf(Category::factory()->create())->create();

    return [
        'product' => Product::factory()->for($category, 'category')->published()
            ->create(['title_tr' => 'Kulaklık', 'title_en' => 'Headphones']),
        // Store names are UNIQUE platform-wide, so a test building two names one.
        'store' => Store::factory()->create(['status' => StoreStatus::Active, 'name' => $storeName]),
    ];
}

function questionsUrl(string $product, string $query = ''): string
{
    return '/api/v1/products/'.$product.'/questions'.($query === '' ? '' : '?'.$query);
}

it('shows answered questions and hides everything else', function (): void {
    $fixture = questionedProductFixture();
    $product = $fixture['product']->uuid;

    $answered = Question::factory()->forProduct($product)->forStore($fixture['store']->uuid)
        ->answered('Evet, kutusunda kablo çıkıyor.')->create(['body' => 'Kablo dahil mi?']);

    // Waiting on the merchant — private to them, the admin and the asker.
    Question::factory()->forProduct($product)->pending()->create();
    // Answered, then taken down by an admin.
    Question::factory()->forProduct($product)->answered()->hidden()->create();

    $response = $this->getJson(questionsUrl($product))->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $answered->uuid)
        ->assertJsonPath('data.0.body', 'Kablo dahil mi?')
        ->assertJsonPath('data.0.answer_body', 'Evet, kutusunda kablo çıkıyor.')
        // BOTH DATES: an answer three months after the question says something a
        // single timestamp cannot.
        ->assertJsonPath('meta.total', 1);

    expect($response->json('data.0.asked_at'))->not->toBeNull()
        ->and($response->json('data.0.answered_at'))->not->toBeNull();
});

it('carries no summary block, because there is nothing to average', function (): void {
    $fixture = questionedProductFixture();

    Question::factory()->forProduct($fixture['product']->uuid)->answered()->create();

    $meta = $this->getJson(questionsUrl($fixture['product']->uuid))->assertOk()->json('meta');

    /*
     * **THE SHAPE OF THE MODULE, ASSERTED AS AN ABSENCE.** Reviews' equivalent
     * endpoint carries an average, a distribution and a count here because a
     * rating rolls up. A question has no rating — so pagination is the only meta,
     * and a client that went looking for stars finds none rather than zeros.
     */
    expect($meta)->toHaveKeys(['current_page', 'per_page', 'total', 'last_page'])
        ->and($meta)->not->toHaveKey('summary');
});

it('never exposes who asked beyond a masked name', function (): void {
    $fixture = questionedProductFixture();

    Question::factory()->forProduct($fixture['product']->uuid)->forStore($fixture['store']->uuid)
        ->forCustomer(42, 'gizli-soran-uuid')->answered()->create(['asker_name' => 'Abdullah Ç.']);

    $response = $this->getJson(questionsUrl($fixture['product']->uuid))->assertOk();

    $response->assertJsonPath('data.0.asker_name', 'Abdullah Ç.');

    /*
     * NO CUSTOMER UUID, and no `answered_by` either: the shopper sees the SHOP's
     * answer, not which colleague typed it.
     */
    expect(json_encode($response->json()))->not->toContain('gizli-soran-uuid');

    $response->assertJsonMissing(['customer_id' => 42])
        ->assertJsonMissing(['answered_by' => 1]);
});

it('names the shop the question was aimed at', function (): void {
    $fixture = questionedProductFixture();

    Question::factory()->forProduct($fixture['product']->uuid)->forStore($fixture['store']->uuid)
        ->answered()->create();

    // Through `StoreQueryContract` — Questions may not import Store — batched for
    // the whole page.
    $this->getJson(questionsUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonPath('data.0.seller.id', $fixture['store']->uuid)
        ->assertJsonPath('data.0.seller.name', 'Deniz Elektronik')
        ->assertJsonPath('data.0.seller.slug', $fixture['store']->slug);
});

it('narrows to the seller a shopper is buying from', function (): void {
    $fixture = questionedProductFixture();
    $product = $fixture['product']->uuid;
    $otherShop = (string) Str::uuid();

    Question::factory()->forProduct($product)->forStore($fixture['store']->uuid)->answered()->create();
    Question::factory()->forProduct($product)->forStore($otherShop)->answered()->count(2)->create();

    // One product page carries every seller's Q&A (the catalogue is shared), so
    // "bu satıcıya sorulanlar" is a filter on one set.
    $this->getJson(questionsUrl($product, 'seller='.$fixture['store']->uuid))->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(questionsUrl($product, 'seller='.$otherShop))->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson(questionsUrl($product))->assertOk()->assertJsonCount(3, 'data');
});

it('opens by slug as well as by uuid, and 404s anything else', function (): void {
    $fixture = questionedProductFixture();

    Question::factory()->forProduct($fixture['product']->uuid)->answered()->create();

    /*
     * A SLUG NEVER REACHES A UUID COLUMN (ADR-059) — resolved through
     * `CatalogBrowseContract` before anything is queried, or this is
     * SQLSTATE[22P02] on PostgreSQL: a 500 on a public page.
     */
    $this->getJson(questionsUrl($fixture['product']->slug))->assertOk()->assertJsonCount(1, 'data');

    $this->getJson(questionsUrl('boyle-bir-urun-yok'))->assertNotFound();
    $this->getJson(questionsUrl((string) Str::uuid()))->assertNotFound();
});

it('answers a product nobody has asked about with an empty list', function (): void {
    $fixture = questionedProductFixture();

    // Not a 404 and not an error: "no questions yet" is the ordinary state of
    // most products, and a section that renders empty is the right outcome.
    $this->getJson(questionsUrl($fixture['product']->uuid))->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});
