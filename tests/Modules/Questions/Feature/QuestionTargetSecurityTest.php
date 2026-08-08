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
| The target under attack (Questions.md §10)
|--------------------------------------------------------------------------
|
| `QuestionActionsTest` proves the derivation against a faked port;
| `CustomerQuestionApiTest` proves the happy path end to end. This is neither: it
| is the adversarial pass, over HTTP, against the real Offer port, stuffing the
| request with every field that could subvert it.
|
| **THE ATTACK THAT WOULD BE SILENT IS THE TARGET.** A forged `store_uuid` throws
| nothing — it just puts a hostile question in an innocent merchant's queue, with
| their name on the answer when they reply. Everything else here is loud by
| comparison.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

/**
 * A product sold by ONE shop, plus a second shop that sells nothing.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{product: Product, realStore: Store, innocentStore: Store}
 */
function questionTargetFixture(): array
{
    $organization = Organization::factory()->create();
    $realStore = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
        'name' => 'Gerçek Satıcı',
    ]);
    $innocentStore = Store::factory()->create([
        'status' => StoreStatus::Active,
        'name' => 'Masum Satıcı',
    ]);

    $category = Category::factory()->childOf(Category::factory()->create())->create();
    $product = Product::factory()->for($category, 'category')->published()
        ->create(['title_tr' => 'Kulaklık', 'title_en' => 'Headphones']);
    $variant = ProductVariant::factory()->for($product)->create();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $organization->getKey(),
        sellingOrgUuid: $organization->uuid,
        storeUuid: $realStore->uuid,
        priceMinor: 12_000,
        stockQuantity: 5,
    ));

    return ['product' => $product, 'realStore' => $realStore, 'innocentStore' => $innocentStore];
}

it('cannot be talked into aiming at a shop that is not selling the product', function (): void {
    $this->actingAsCustomer();
    $fixture = questionTargetFixture();

    /*
     * **THE SILENT ATTACK.** A forged target throws no error — it just drops a
     * hostile question into an innocent merchant's queue, and their name goes on
     * the answer when they reply. So the request carries every field that could
     * carry one, and every one must be ignored: `AskQuestionDTO` has no property
     * to receive them, and the action reads the buy box instead.
     */
    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Bu ürün sahte mi?',
        'store_uuid' => $fixture['innocentStore']->uuid,
        'store' => $fixture['innocentStore']->uuid,
        'seller' => $fixture['innocentStore']->uuid,
        'seller_id' => $fixture['innocentStore']->uuid,
        'selling_org_uuid' => (string) Str::uuid(),
    ])->assertCreated();

    $question = Question::query()->firstOrFail();

    expect($question->store_uuid)->toBe($fixture['realStore']->uuid)
        ->and($question->store_uuid)->not->toBe($fixture['innocentStore']->uuid);
});

it('cannot be talked into publishing itself', function (): void {
    $this->actingAsCustomer();
    $fixture = questionTargetFixture();

    // The fields that would make the seller's answer meaningless — a question
    // that arrives already "answered" is a shopper writing the merchant's reply.
    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Kendi kendime cevap veriyorum.',
        'status' => QuestionStatus::Answered->value,
        'answer_body' => 'Evet, harika bir ürün!',
        'answered_at' => now()->toIso8601String(),
        'answered_by' => 1,
    ])->assertCreated();

    $question = Question::query()->firstOrFail();

    expect($question->status)->toBe(QuestionStatus::Pending)
        ->and($question->answer_body)->toBeNull()
        ->and($question->isPublic())->toBeFalse();

    // And it is invisible where it counts.
    $this->getJson('/api/v1/products/'.$fixture['product']->uuid.'/questions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('cannot be talked into hiding itself, or into signing another name', function (): void {
    $this->actingAsCustomer(
        Customer::factory()->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz']),
    );
    $fixture = questionTargetFixture();

    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'Kargo ne zaman çıkar?',
        'asker_name' => 'Mehmet K.',
        'customer_id' => 999,
        'customer_uuid' => (string) Str::uuid(),
        'hidden_at' => now()->toIso8601String(),
        'hidden_by' => 1,
    ])->assertCreated();

    $question = Question::query()->firstOrFail();

    // Computed from the ACTOR, never the payload — and the admin's lever is not
    // something a shopper can pull on their own question.
    expect($question->asker_name)->toBe('Ayşe Y.')
        ->and($question->customer_id)->toBe((int) auth('customer')->id())
        ->and($question->isHidden())->toBeFalse();
});

it('re-derives the target on every ask rather than reusing the last one', function (): void {
    $this->actingAsCustomer();
    $fixture = questionTargetFixture();

    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'İlk sorum.',
    ])->assertCreated();

    // Somebody undercuts the winner: a second shop offers the same product cheaper.
    $rivalOrg = Organization::factory()->create();
    $rivalStore = Store::factory()->create([
        'organization_id' => $rivalOrg->getKey(),
        'status' => StoreStatus::Active,
        'name' => 'Ucuzcu Satıcı',
    ]);

    /** @var ProductVariant $variant */
    $variant = ProductVariant::query()->where('product_id', $fixture['product']->getKey())->firstOrFail();

    app(CreateOfferAction::class)->run(new CreateOfferDTO(
        variantUuid: $variant->uuid,
        sellingOrgId: $rivalOrg->getKey(),
        sellingOrgUuid: $rivalOrg->uuid,
        storeUuid: $rivalStore->uuid,
        priceMinor: 9_000,
        stockQuantity: 5,
    ));

    $this->postJson('/api/v1/questions', [
        'product' => $fixture['product']->uuid,
        'body' => 'İkinci sorum.',
    ])->assertCreated();

    /*
     * **THE BUY BOX IS READ ON EVERY ASK, NOT COPIED FROM THE LAST QUESTION** —
     * so the second goes to the new winner while the first stays with the shop
     * the earlier shopper was actually looking at (ADR-070). Both halves matter:
     * re-deriving keeps questions going to whoever is selling now, and the
     * snapshot keeps a past answer attributable to whoever gave it.
     */
    $questions = Question::query()->orderBy('id')->get();

    expect($questions)->toHaveCount(2)
        ->and($questions[0]->store_uuid)->toBe($fixture['realStore']->uuid)
        ->and($questions[1]->store_uuid)->toBe($rivalStore->uuid);
});
