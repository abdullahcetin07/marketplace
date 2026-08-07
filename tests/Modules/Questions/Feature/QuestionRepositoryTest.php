<?php

declare(strict_types=1);

use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\QuestionListFilterDTO;
use App\Modules\Questions\Domain\Models\Question;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| What the public may read (ADR-070)
|--------------------------------------------------------------------------
|
| **AN UNANSWERED QUESTION IS PRIVATE TO THREE PEOPLE** — the target seller, an
| admin and the asker. Publishing one early would put a shopper's words on a
| product page before the merchant they were aimed at had seen them, which is the
| opposite of what "Satıcıya Sor" promises.
|
| And the hide has to work on BOTH states, which is the whole reason it is a flag
| rather than a third status.
|
*/

beforeEach(function (): void {
    $this->questions = app(QuestionRepositoryContract::class);
    $this->product = (string) Str::uuid();
});

it('publishes only what the seller has answered', function (): void {
    $product = $this->product;

    $answered = Question::factory()->forProduct($product)->answered()->create();
    Question::factory()->forProduct($product)->pending()->create();

    $page = $this->questions->publicForProduct($product, new QuestionListFilterDTO);

    /*
     * THE SELLER'S ANSWER IS WHAT PUBLISHES IT — no moderator in the path, which
     * is this module's difference from Reviews. The pending one is not "waiting
     * for staff"; it is waiting for the merchant.
     */
    expect($page->total())->toBe(1)
        ->and($page->items()[0]->uuid)->toBe($answered->uuid);
});

it('hides an answered question when an admin takes it down, and restores it', function (): void {
    $product = $this->product;

    $question = Question::factory()->forProduct($product)->answered()->create();

    expect($this->questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(1);

    $question->forceFill(['hidden_at' => now(), 'hidden_by' => 1, 'hidden_reason' => 'Küfür'])->save();

    expect($this->questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(0);

    /*
     * **AND UN-HIDING RESTORES WHATEVER IT WAS.** That reversibility is why
     * hiding is a nullable column rather than a status: there is no "was it
     * answered before it was hidden?" to reconstruct, because the status never
     * changed.
     */
    $question->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->save();

    expect($this->questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(1);
});

it('keeps a hidden PENDING question off every surface, including the seller’s', function (): void {
    $product = $this->product;
    $store = (string) Str::uuid();

    Question::factory()->forProduct($product)->forStore($store)->pending()->hidden()->create();

    /*
     * **THE CASE A STATUS ENUM COULD NOT HAVE MODELLED.** An admin takes down an
     * abusive question BEFORE the merchant reads it — that is most of what the
     * hide is for. It was never public, and now it is not in the seller's queue
     * either.
     */
    expect($this->questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(0)
        ->and(Question::query()->forStore($store)->visibleToSeller()->count())->toBe(0)
        // The row is still there — hiding is not deleting.
        ->and(Question::query()->forStore($store)->count())->toBe(1);
});

it('narrows to the seller a shopper is actually buying from', function (): void {
    $product = $this->product;
    $shopA = (string) Str::uuid();
    $shopB = (string) Str::uuid();

    Question::factory()->forProduct($product)->forStore($shopA)->answered()->count(2)->create();
    Question::factory()->forProduct($product)->forStore($shopB)->answered()->create();

    /*
     * ONE PRODUCT PAGE CARRIES EVERY SELLER'S Q&A (the catalogue is shared), so
     * "bu satıcıya sorulanlar" is a FILTER on one set — the same shape Reviews'
     * seller filter has.
     */
    expect($this->questions->publicForProduct($product, new QuestionListFilterDTO(sellerStoreUuid: $shopA))->total())
        ->toBe(2)
        ->and($this->questions->publicForProduct($product, new QuestionListFilterDTO(sellerStoreUuid: $shopB))->total())
        ->toBe(1)
        ->and($this->questions->publicForProduct($product, new QuestionListFilterDTO)->total())
        ->toBe(3);
});

it('does not leak another product’s questions', function (): void {
    Question::factory()->forProduct($this->product)->answered()->create();
    Question::factory()->forProduct((string) Str::uuid())->answered()->create();

    expect($this->questions->publicForProduct($this->product, new QuestionListFilterDTO)->total())->toBe(1);
});

it('shows an asker everything they asked, pending and hidden included', function (): void {
    Question::factory()->forCustomer(7, 'soran')->pending()->create();
    Question::factory()->forCustomer(7, 'soran')->answered()->create();
    Question::factory()->forCustomer(7, 'soran')->answered()->hidden()->create();
    Question::factory()->forCustomer(8, 'baskasi')->answered()->create();

    /*
     * THE HIDDEN ONE IS THE HARD CALL AND IT LANDS THE SAME WAY: it is still
     * THEIR question, and making it vanish from their own list would be the
     * platform editing somebody's history without telling them. A shopper who
     * cannot see their pending one asks it again believing it was lost.
     */
    expect($this->questions->forCustomer(7))->toHaveCount(3)
        ->and($this->questions->forCustomer(8))->toHaveCount(1);
});

it('takes the answer with the question when one is deleted', function (): void {
    $question = Question::factory()->answered()->create();

    $this->questions->delete($question);

    /*
     * A HARD DELETE, and the seller's answer goes with it — correct, because the
     * answer only ever existed as a reply to that question. Leaving it orphaned
     * would publish half an exchange.
     */
    expect(Question::query()->count())->toBe(0);
});
