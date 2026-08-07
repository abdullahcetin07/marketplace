<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Seller;
use App\Modules\Questions\Application\Actions\HideQuestionAction;
use App\Modules\Questions\Application\Actions\UnhideQuestionAction;
use App\Modules\Questions\Domain\Contracts\QuestionRepositoryContract;
use App\Modules\Questions\Domain\DTOs\HideQuestionDTO;
use App\Modules\Questions\Domain\DTOs\QuestionListFilterDTO;
use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;
use App\Modules\Questions\Presentation\Filament\Resources\QuestionModerationResource;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The platform's only lever (ADR-071)
|--------------------------------------------------------------------------
|
| **AN ADMIN HIDES AND NEVER ANSWERS.** That asymmetry is the module's central
| rule and the easiest one to erode: somebody would "fix" a slow merchant by
| letting staff reply, and the platform would start making promises the merchant
| did not.
|
| The mirror holds too — a SELLER cannot hide. The party a question is aimed at
| does not get to make it disappear.
|
*/

beforeEach(function (): void {
    $this->seedAll();
});

it('lets an admin hide an answered pair, and the public page loses it', function (): void {
    $product = (string) Str::uuid();
    $question = Question::factory()->forProduct($product)->answered()->create();

    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));
    $this->actingAs($admin, 'admin');

    $questions = app(QuestionRepositoryContract::class);

    expect($questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(1);

    app(HideQuestionAction::class)->run($question, new HideQuestionDTO(
        hiddenBy: (int) $admin->getKey(),
        reason: 'Küfür içeriyor',
    ));

    expect($questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(0)
        ->and($question->fresh()->hidden_reason)->toBe('Küfür içeriyor')
        // NOT A DELETE. A takedown somebody can undo is the right shape for a
        // judgement made in seconds on somebody else's words.
        ->and(Question::query()->count())->toBe(1);

    app(UnhideQuestionAction::class)->run($question);

    /*
     * AND UN-HIDING RESTORES WHAT IT WAS, with nothing to reconstruct: hiding
     * never touched the status.
     */
    expect($questions->publicForProduct($product, new QuestionListFilterDTO)->total())->toBe(1)
        ->and($question->fresh()->status)->toBe(QuestionStatus::Answered);
});

it('gives an admin no way to answer', function (): void {
    $question = Question::factory()->pending()->create();

    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));
    $this->actingAs($admin, 'admin');

    /*
     * **THE ASYMMETRY, ASSERTED FROM BOTH SIDES.** An admin can see and judge an
     * unanswered question and cannot reply to it — `QuestionPolicy::answer()`
     * refuses on the actor TYPE, so even an admin somehow granted
     * `question.answer` is still refused. The platform speaking in a merchant's
     * place is a promise the merchant did not make.
     */
    expect($admin->can('moderate', Question::class))->toBeTrue()
        ->and($admin->can('answer', $question))->toBeFalse();
});

it('lets an editor moderate, because a Q&A is user text', function (): void {
    $editor = Admin::factory()->create();
    $editor->assignRole(config('marketplace.roles.editor'));
    $this->actingAs($editor, 'admin');

    // The same reasoning that gives Editor the review queue: a shopper's question
    // and a merchant's answer are content, which is this role's business.
    expect($editor->can('moderate', Question::class))->toBeTrue()
        ->and(QuestionModerationResource::canViewAny())->toBeTrue()
        // And still no answering — no admin-guard user holds `question.answer`.
        ->and($editor->can('answer', Question::factory()->pending()->create()))->toBeFalse();
});

it('keeps every seller away from the hide', function (): void {
    $seller = Seller::factory()->create();
    $seller->assignRole(config('marketplace.roles.seller'));
    $this->actingAs($seller, 'seller');

    /*
     * **THE MIRROR OF THE RULE ABOVE.** A merchant able to make an awkward
     * question disappear would make every surviving answer on the page
     * worthless. The policy refuses on the actor type, so it is not a matter of
     * an unheld permission.
     */
    expect($seller->can('moderate', Question::class))->toBeFalse()
        ->and(QuestionModerationResource::canViewAny())->toBeFalse();
});

it('offers no create, edit or delete on the moderation screen', function (): void {
    $question = Question::factory()->answered()->create();

    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));
    $this->actingAs($admin, 'admin');

    /*
     * EDITING WOULD BE THE PLATFORM REWRITING either a shopper's question or a
     * merchant's answer; DELETING would be a destructive lever where a reversible
     * one already exists.
     */
    expect(QuestionModerationResource::canCreate())->toBeFalse()
        ->and(QuestionModerationResource::canEdit($question))->toBeFalse()
        ->and(QuestionModerationResource::canDelete($question))->toBeFalse();
});

it('reads across every seller, with no tenancy scope', function (): void {
    Question::factory()->forStore((string) Str::uuid())->answered()->create();
    Question::factory()->forStore((string) Str::uuid())->pending()->create();
    Question::factory()->forStore((string) Str::uuid())->answered()->hidden()->create();

    $admin = Admin::factory()->create();
    $admin->assignRole(config('marketplace.roles.admin'));
    $this->actingAs($admin, 'admin');

    /*
     * ALL THREE, INCLUDING THE HIDDEN ONE. This is the one surface that sees a
     * hidden question — the seller's queue and the public page both drop it — and
     * it has to, or nobody could ever un-hide one.
     */
    expect(QuestionModerationResource::getEloquentQuery()->count())->toBe(3);
});
