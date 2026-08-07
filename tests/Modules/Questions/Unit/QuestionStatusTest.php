<?php

declare(strict_types=1);

use App\Modules\Questions\Domain\Enums\QuestionStatus;
use App\Modules\Questions\Domain\Models\Question;

/*
|--------------------------------------------------------------------------
| What a question's status can be, and what hiding is instead (ADR-070)
|--------------------------------------------------------------------------
|
| The enum has two cases and the interesting thing about it is the third one it
| does NOT have. Hiding is a reversible FLAG, because an admin may take down a
| question that has not been answered yet as well as one that has — a status
| could not hold both without losing the state it came from.
|
*/

it('has exactly the two states the seller drives', function (): void {
    /*
     * NO `Hidden` — @see the file header. NO `Declined` either: a seller who
     * does not want to answer leaves it pending, because a refusal button would
     * publish the fact that a merchant refused, which is a worse answer than
     * silence (§11).
     */
    expect(array_map(fn (QuestionStatus $s): string => $s->value, QuestionStatus::cases()))
        ->toBe(['pending', 'answered']);
});

it('separates "answered" from "public"', function (): void {
    expect(QuestionStatus::Pending->isPending())->toBeTrue()
        ->and(QuestionStatus::Answered->isAnswered())->toBeTrue()
        ->and(QuestionStatus::Pending->isAnswered())->toBeFalse();

    /*
     * **THE DISTINCTION THE ENUM DELIBERATELY CANNOT MAKE.** An answered question
     * an admin has hidden is answered and invisible, so visibility is a
     * conjunction of two columns and lives on the MODEL, where both are in scope.
     */
    $answered = new Question(['status' => QuestionStatus::Answered]);
    expect($answered->isPublic())->toBeTrue();

    $answered->hidden_at = now();
    expect($answered->isPublic())->toBeFalse()
        ->and($answered->isHidden())->toBeTrue()
        // Still answered. Hiding does not rewrite what happened.
        ->and($answered->status->isAnswered())->toBeTrue();

    // And a pending question is not public however un-hidden it is.
    expect((new Question(['status' => QuestionStatus::Pending]))->isPublic())->toBeFalse();
});

it('gives every case a colour and a translated label', function (): void {
    foreach (QuestionStatus::cases() as $status) {
        expect($status->color())->toBeIn(['warning', 'success'])
            ->and($status->label())->not->toBe('');
    }
});

it('casts a question the way its surfaces read it', function (): void {
    $casts = (new Question)->getCasts();

    expect($casts['status'])->toBe(QuestionStatus::class)
        /*
         * BOTH STAMPS IMMUTABLE: each records a past decision, and nothing should
         * be able to nudge one by mutating the instance it was read into. There
         * is no money cast here at all — no rating, no price (§10).
         */
        ->and($casts['answered_at'])->toBe('immutable_datetime')
        ->and($casts['hidden_at'])->toBe('immutable_datetime');
});
