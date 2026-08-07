<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use App\Modules\Offer\Application\Actions\CreateOfferAction;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The shopper's own surfaces (Questions.md §8)
|--------------------------------------------------------------------------
|
| END TO END, THROUGH THE REAL OFFER PORT — no fake here, unlike
| `QuestionActionsTest`. That file proves the action's logic in isolation; this
| one proves the whole chain: a real sellable offer, its buy-box winner read
| through the Core contract, the target frozen onto the question, and the pair
| invisible until the merchant answers.
|
| **THE HEADLINE ASSERTION IS A NEGATIVE ONE:** a customer who has never bought
| anything can ask. That is what separates this module from Reviews, and it is
| easier to break than to notice.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A published product with one live, in-stock offer — so there is a buy box.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, store: Store, org: Organization}
 */
function askableProductFixture(string $storeName = 'Deniz Elektronik'): array
{
    $organization = Organization::factory()->create();
    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
        'name' => $storeName,
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Kulaklık', 'title_en' => 'Headphones']);
    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $store->uuid,
        priceMinor: 12_000,
        stockQuantity: 5,
    ));

    return ['product' => $product, 'store' => $store, 'org' => $organization];
}

it('lets a customer who has never bought anything ask', function (): void {
    $this->actingAsCustomer();
    $fixture = askableProductFixture();

    $response = $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Kutusundan kablo çıkıyor mu?',
    ])->assertCreated();

    /*
     * **NO ORDER, NO DELIVERED LINE, NO GATE** (ADR-070). A question is asked to
     * decide WHETHER to buy, so requiring a purchase would defeat the feature —
     * which is exactly the opposite of `CreateReviewAction`'s first act.
     *
     * AND THE 201 SAYS `pending`, so the UI says "sorunuz satıcıya iletildi"
     * rather than showing it as live.
     */
    $response->assertJsonPath('data.status', QuestionStatus::Pending->value)
        ->assertJsonPath('data.body', 'Kutusundan kablo çıkıyor mu?')
        ->assertJsonPath('data.product_title', $fixture['product']->localized('title'))
        ->assertJsonPath('data.answer_body', null);

    // The target came from the BUY BOX, not the request.
    expect(Question::query()->firstOrFail()->store_uuid)->toBe($fixture['store']->uuid);

    // Invisible to everyone else until the merchant answers.
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/questions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('signs the question with a masked name taken from the account', function (): void {
    $this->actingAsCustomer(
        Customer::factory()->create(['first_name' => 'Abdullah', 'last_name' => 'Çetin']),
    );
    $fixture = askableProductFixture();

    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Garanti süresi ne kadar?',
    ])->assertCreated();

    // From the ACTOR, never the payload, and stored already masked.
    expect(Question::query()->firstOrFail()->asker_name)->toBe('Abdullah Ç.');
});

it('refuses when nobody is selling the product', function (): void {
    $this->actingAsCustomer();

    // Published, catalogued — and no offer, so no buy box and nobody to ask.
    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()->create();

    $this->postJson('/api/v1/questions', [
        'product' => $product->uuid,
        'body' => 'Bu ürün hakkında bilgi alabilir miyim?',
    ])->assertStatus(422);

    expect(Question::query()->count())->toBe(0);
});

it('accepts a slug and 404s a product that does not exist', function (): void {
    $this->actingAsCustomer();
    $fixture = askableProductFixture();

    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->slug,
        'body' => 'Stokta kaç adet var?',
    ])->assertCreated();

    $this->postJson('/api/v1/questions', [
        'product' => 'boyle-bir-urun-yok',
        'body' => 'Stokta kaç adet var?',
    ])->assertNotFound();
});

it('refuses an empty or one-word question', function (): void {
    $this->actingAsCustomer();
    $fixture = askableProductFixture();

    // The bar is intentionally low — "Kaça?" is a real question — but an empty
    // submission must not reach a merchant's queue.
    foreach (['', 'a'] as $body) {
        $this->postJson('/api/v1/questions', [
            'product' => $fixture['product']->uuid,
            'body' => $body,
        ])->assertStatus(422);
    }
});

it('lists a shopper’s own questions in every state, and nobody else’s', function (): void {
    $customer = $this->actingAsCustomer();
    $fixture = askableProductFixture();

    Question::factory()->forCustomer((int) $customer->getKey(), $customer->uuid)
        ->forProduct($fixture['product']->uuid)->pending()->create();
    Question::factory()->forCustomer((int) $customer->getKey(), $customer->uuid)
        ->forProduct($fixture['product']->uuid)->answered('Evet, var.')->create();
    /*
     * A HIDDEN ONE STILL APPEARS — it is still THEIR question, and removing it
     * would be the platform editing somebody's history without telling them. The
     * resource says nothing about the hide, which is the deliberate silence.
     */
    Question::factory()->forCustomer((int) $customer->getKey(), $customer->uuid)
        ->forProduct($fixture['product']->uuid)->answered()->hidden()->create();

    Question::factory()->forCustomer(999, 'baskasi')->answered()->create();

    $response = $this->getJson('/api/v1/questions/mine')->assertOk();

    $response->assertJsonCount(3, 'data');

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $response->json('data');
    $answers = array_values(array_filter(array_map(
        static fn (array $row): ?string => $row['answer_body'],
        $rows,
    )));

    expect($answers)->toContain('Evet, var.')
        // The hide is never named.
        ->and(json_encode($rows))->not->toContain('hidden');
});

it('lets a shopper take their own question back, answered or not', function (): void {
    $this->actingAsCustomer();
    $fixture = askableProductFixture();

    $created = $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Yanlışlıkla sordum, silmek istiyorum.',
    ])->assertCreated()->json('data.id');

    $this->deleteJson('/api/v1/questions/'.$created)->assertNoContent();

    expect(Question::query()->count())->toBe(0);
});

it('will not let one shopper delete another’s question', function (): void {
    $this->actingAsCustomer();

    $theirs = Question::factory()->forCustomer(999, 'baskasi')->answered()->create();

    $this->deleteJson('/api/v1/questions/'.$theirs->uuid)->assertForbidden();

    expect(Question::query()->count())->toBe(1);
});

it('404s a question uuid that is malformed or unknown', function (): void {
    $this->actingAsCustomer();

    /*
     * BY SHAPE FIRST (ADR-059). `questions.uuid` is a native uuid column on
     * PostgreSQL, so a malformed segment would be SQLSTATE[22P02] — a 500 on a
     * button the shopper taps.
     */
    $this->deleteJson('/api/v1/questions/not-a-uuid')->assertNotFound();
    $this->deleteJson('/api/v1/questions/'.Str::uuid()->toString())->assertNotFound();
});

it('keeps a seller and an anonymous visitor off the asker endpoints', function (): void {
    $this->getJson('/api/v1/questions/mine')->assertUnauthorized();

    $this->actingAs(App\Models\Seller::factory()->create(), 'seller');

    /*
     * A MERCHANT DOES NOT ASK QUESTIONS OF THEMSELVES. The request refuses any
     * actor type that is not a customer — the mirror of the rule that keeps a
     * customer out of the answer panel.
     */
    $this->postJson('/api/v1/questions', [
        'product' => (string) Str::uuid(),
        'body' => 'Bir sorum var.',
    ])->assertForbidden();
});
