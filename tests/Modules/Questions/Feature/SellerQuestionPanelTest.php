<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Seller;
use App\Modules\Organization\Domain\Enums\OrganizationRole;
use App\Modules\Organization\Domain\Models\Organization;
use App\Modules\Organization\Domain\Models\OrganizationMember;
use App\Modules\Questions\Application\Actions\AnswerQuestionAction;
use App\Modules\Questions\Domain\DTOs\AnswerQuestionDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Filament\Seller\Resources\QuestionResource;
use App\Modules\Store\Domain\Enums\StoreStatus;
use App\Modules\Store\Domain\Models\Store;

/*
|--------------------------------------------------------------------------
| Who may answer, and for which shop (ADR-071)
|--------------------------------------------------------------------------
|
| **THE TARGET SNAPSHOT IS THE ISOLATION.** A question carries the store it was
| aimed at, so "my questions" is a `store_uuid` comparison — and a merchant
| answering somebody else's would be putting words in another shop's mouth on a
| public page.
|
| The wall is stated twice on purpose: the panel's query scopes it, and
| `QuestionPolicy::answer()` asks again on the way in. A panel scope is a query
| somebody can get wrong; the policy is the side that cannot be.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

/**
 * A seller who owns one live store.
 *
 * Named for this file: Pest shares ONE global function namespace.
 *
 * @return array{seller: Seller, store: Store, org: Organization}
 */
function sellerWithStore(string $storeName): array
{
    /** @var Seller $seller */
    $seller = Seller::factory()->create();
    $seller->assignRole(config('marketplace.roles.seller'));

    $organization = Organization::factory()->create(['owner_id' => $seller->getKey()]);
    OrganizationMember::factory()->for($organization)->role(OrganizationRole::Owner)
        ->create(['user_id' => $seller->getKey()]);

    $store = Store::factory()->create([
        'organization_id' => $organization->getKey(),
        'status' => StoreStatus::Active,
        'name' => $storeName,
    ]);

    return ['seller' => $seller, 'store' => $store, 'org' => $organization];
}

it('shows a merchant only the questions aimed at their own shop', function (): void {
    $mine = sellerWithStore('Benim Dükkanım');
    $theirs = sellerWithStore('Onun Dükkanı');

    Question::factory()->forStore($mine['store']->uuid)->pending()->count(2)->create();
    Question::factory()->forStore($theirs['store']->uuid)->pending()->create();

    $this->actingAs($mine['seller'], 'seller');

    expect(QuestionResource::getEloquentQuery()->count())->toBe(2)
        // The badge counts what is WAITING on this merchant, and goes to zero
        // when they are done.
        ->and(QuestionResource::getNavigationBadge())->toBe('2');

    $this->actingAs($theirs['seller'], 'seller');

    expect(QuestionResource::getEloquentQuery()->count())->toBe(1);
});

it('hides an admin-hidden question from the merchant too', function (): void {
    $shop = sellerWithStore('Gizli Soru Dükkanı');

    Question::factory()->forStore($shop['store']->uuid)->pending()->create();
    Question::factory()->forStore($shop['store']->uuid)->pending()->hidden()->create();

    $this->actingAs($shop['seller'], 'seller');

    /*
     * **NOT JUST OFF THE PUBLIC PAGE.** An admin hides abuse, and leaving it in
     * the merchant's queue would make them read it anyway — which is most of what
     * the hide was for.
     */
    expect(QuestionResource::getEloquentQuery()->count())->toBe(1);
});

it('lets the target merchant answer, and publishes the pair', function (): void {
    $shop = sellerWithStore('Cevaplayan Dükkan');
    $question = Question::factory()->forStore($shop['store']->uuid)->pending()->create();

    $this->actingAs($shop['seller'], 'seller');

    expect($shop['seller']->can('answer', $question))->toBeTrue();

    app(AnswerQuestionAction::class)->run(
        $question,
        new AnswerQuestionDTO(answerBody: 'Evet, kutusunda kablo var.', answeredBy: (int) $shop['seller']->getKey()),
    );

    /*
     * ANSWERING IS PUBLISHING (ADR-070) — no moderator in the path, which is the
     * mirror of Reviews. The pair is live the moment it is saved.
     */
    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->fresh()->isPublic())->toBeTrue()
        ->and($question->fresh()->answered_by)->toBe((int) $shop['seller']->getKey());
});

it('refuses a merchant answering a question aimed at another shop', function (): void {
    $mine = sellerWithStore('Benim Dükkanım');
    $theirs = sellerWithStore('Onun Dükkanı');

    $notMine = Question::factory()->forStore($theirs['store']->uuid)->pending()->create();

    $this->actingAs($mine['seller'], 'seller');

    /*
     * **PUTTING WORDS IN ANOTHER SHOP'S MOUTH, ON A PUBLIC PAGE.** The policy
     * refuses even though this seller holds `question.answer` — the permission is
     * only half of it; the target's store must be one of theirs.
     */
    expect($mine['seller']->can('answer', $notMine))->toBeFalse();
});

it('lets a seller employee answer, because it is delegable staff work', function (): void {
    $shop = sellerWithStore('Ekipli Dükkan');
    $question = Question::factory()->forStore($shop['store']->uuid)->pending()->create();

    /** @var Seller $employee */
    $employee = Seller::factory()->create();
    $employee->assignRole(config('marketplace.roles.seller_employee'));
    OrganizationMember::factory()->for($shop['org'])->role(OrganizationRole::Support)
        ->create(['user_id' => $employee->getKey()]);

    $this->actingAs($employee, 'seller');

    /*
     * A SHOPPER WAITING ON "kutusundan kablo çıkıyor mu?" SHOULD NOT WAIT FOR THE
     * OWNER PERSONALLY (ADR-071) — the same reasoning that gives an employee
     * product authoring. Still confined to the organization's own stores.
     */
    expect($employee->can('answer', $question))->toBeTrue()
        ->and(QuestionResource::canViewAny())->toBeTrue();
});

it('keeps a customer out of the answer panel entirely', function (): void {
    $shop = sellerWithStore('Müşteriye Kapalı');
    $question = Question::factory()->forStore($shop['store']->uuid)->pending()->create();

    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer');

    // The mirror of the rule keeping a seller off the asker endpoints: a shopper
    // answering their own question would be an advertisement in a buyer's name.
    expect($customer->can('answer', $question))->toBeFalse()
        ->and(QuestionResource::canViewAny())->toBeFalse();
});

it('offers a merchant no way to create, edit or delete a question', function (): void {
    $shop = sellerWithStore('Sadece Cevap');
    $question = Question::factory()->forStore($shop['store']->uuid)->pending()->create();

    $this->actingAs($shop['seller'], 'seller');

    /*
     * ONE LEVER AND ONLY ONE. A merchant does not write questions (that is an
     * advertisement in a shopper's name), does not rewrite one (their answer
     * would end up attached to something nobody asked), and does not delete one
     * aimed at them — that is the suppression `question.moderate` deliberately
     * keeps out of their hands.
     */
    expect(QuestionResource::canCreate())->toBeFalse()
        ->and(QuestionResource::canEdit($question))->toBeFalse()
        ->and(QuestionResource::canDelete($question))->toBeFalse()
        ->and($shop['seller']->can('moderate', Question::class))->toBeFalse();
});
