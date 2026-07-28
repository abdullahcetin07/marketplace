<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\ProductStatus;

/*
|--------------------------------------------------------------------------
| ProductStatus — the moderation state machine (§3.1)
|--------------------------------------------------------------------------
|
| A Unit test: no database (tests/Pest.php). The transition matrix is pure
| logic, and it is the one piece of this module where a wrong answer is not a
| bug report but a governance failure — a product reaching Published without
| passing a moderator.
|
*/

it('walks the happy path from draft to published', function (): void {
    expect(ProductStatus::Draft->canTransitionTo(ProductStatus::PendingReview))->toBeTrue()
        ->and(ProductStatus::PendingReview->canTransitionTo(ProductStatus::Published))->toBeTrue();
});

it('never lets a product reach published without moderation', function (): void {
    // The single most important negative in the enum. Draft → Published would
    // make the whole moderation lifecycle optional.
    expect(ProductStatus::Draft->canTransitionTo(ProductStatus::Published))->toBeFalse()
        ->and(ProductStatus::NeedsRevision->canTransitionTo(ProductStatus::Published))->toBeFalse()
        ->and(ProductStatus::Rejected->canTransitionTo(ProductStatus::Published))->toBeFalse()
        ->and(ProductStatus::Archived->canTransitionTo(ProductStatus::Published))->toBeFalse();
});

it('offers a moderator exactly three verdicts', function (): void {
    expect(ProductStatus::moderationOutcomes())->toBe([
        ProductStatus::Published,
        ProductStatus::NeedsRevision,
        ProductStatus::Rejected,
    ]);

    expect(ProductStatus::PendingReview->allowedTransitions())
        ->toBe(ProductStatus::moderationOutcomes());
});

it('returns a revised product to the queue, not straight to published', function (): void {
    // The NeedsRevision loop: back to the seller, then back through review.
    expect(ProductStatus::NeedsRevision->allowedTransitions())
        ->toBe([ProductStatus::PendingReview, ProductStatus::Archived]);
});

it('lets a rejected proposal be reworked rather than ending it', function (): void {
    // §3.1 — rejection is not a dead end; the seller may rework the idea.
    expect(ProductStatus::Rejected->canTransitionTo(ProductStatus::Draft))->toBeTrue();
});

it('makes archived terminal', function (): void {
    expect(ProductStatus::Archived->allowedTransitions())->toBe([])
        ->and(ProductStatus::Archived->isTerminal())->toBeTrue();

    foreach (ProductStatus::cases() as $case) {
        if ($case !== ProductStatus::Archived) {
            expect($case->isTerminal())->toBeFalse();
        }
    }
});

it('lets a proposal be abandoned from every state except while under review', function (): void {
    // A seller can walk away from a proposal and the row stays in the
    // moderation record (§3.5) — but NOT once it is in the queue.
    //
    // §3.1 enumerates PendingReview's transitions exactly: Published, Rejected,
    // NeedsRevision. Archiving is not among them, and that is coherent with the
    // rest of the design — a product must not change underneath the moderator
    // reading it, and withdrawing it is a change. A seller who submitted by
    // mistake waits for a verdict, which is a moment, not a wall.
    foreach (ProductStatus::cases() as $case) {
        $expected = ! in_array($case, [ProductStatus::Archived, ProductStatus::PendingReview], true);

        expect($case->canTransitionTo(ProductStatus::Archived))->toBe($expected);
    }
});

it('lets the seller edit only when the product is theirs to edit', function (): void {
    // Not while a moderator is reading it, and not once it belongs to the
    // shared catalog (ADR-037).
    expect(ProductStatus::Draft->isSellerEditable())->toBeTrue()
        ->and(ProductStatus::NeedsRevision->isSellerEditable())->toBeTrue()
        ->and(ProductStatus::Rejected->isSellerEditable())->toBeTrue()
        ->and(ProductStatus::PendingReview->isSellerEditable())->toBeFalse()
        ->and(ProductStatus::Published->isSellerEditable())->toBeFalse()
        ->and(ProductStatus::Archived->isSellerEditable())->toBeFalse();
});

it('puts only published products in the queue-and-index answers', function (): void {
    expect(ProductStatus::PendingReview->awaitsModeration())->toBeTrue()
        ->and(ProductStatus::Draft->awaitsModeration())->toBeFalse()
        ->and(ProductStatus::searchable())->toBe([ProductStatus::Published])
        ->and(ProductStatus::Published->isSearchable())->toBeTrue()
        ->and(ProductStatus::Archived->isSearchable())->toBeFalse();
});

it('carries the six cases the spec rules are normative', function (): void {
    // §2.6 — the abbreviated list earlier in review omitted NeedsRevision;
    // §3.1/§5 are normative and these six are the truth.
    expect(ProductStatus::values())->toBe([
        'draft',
        'pending_review',
        'needs_revision',
        'published',
        'rejected',
        'archived',
    ]);
});

it('is a different enum from the Sprint-0 shared placeholder', function (): void {
    // §2.6 — the module owns its real status enum; the Shared placeholder is
    // left untouched and is NOT reused. They share a short name (and therefore
    // their translation block) but not their cases.
    expect(ProductStatus::class)->not->toBe(App\Shared\Enums\ProductStatus::class)
        ->and(ProductStatus::tryFrom('needs_revision'))->not->toBeNull()
        ->and(App\Shared\Enums\ProductStatus::tryFrom('needs_revision'))->toBeNull()
        ->and(ProductStatus::tryFrom('unpublished'))->toBeNull();
});

it('gives every case a badge colour', function (): void {
    foreach (ProductStatus::cases() as $case) {
        expect($case->color())->toBeString();
        expect($case->color())->not->toBeEmpty();
    }
});
