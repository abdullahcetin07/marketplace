<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\DTOs\CommissionSubjectDTO;
use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Domain\Services\CommissionResolver;
use App\Modules\Payment\Domain\Support\CommissionAmount;

/*
|--------------------------------------------------------------------------
| The commission engine (ADR-061, Payment.md §6)
|--------------------------------------------------------------------------
|
| Commission is not one platform rate. The owner composes rates by ADDING ROWS,
| and the engine's whole job is to say which row wins — legibly, because a seller
| asking "why 12%?" deserves a one-sentence answer.
|
|   SPECIFICITY WINS   the rule that says the MOST about this line
|   PRIORITY IS A TIEBREAK ONLY — it can never beat specificity
|   SUBTREE            a rule on a parent category covers its descendants
|   KDV-INCLUSIVE      the base is the gross the buyer paid (owner choice)
|   ONE ROUNDING RULE  charge and refund must agree to the kuruş, forever
|
| `seedPlatform()` already installs the platform default (18%), exactly as
| production has it — so these tests exercise the state the live system is in
| rather than an empty table it never sees.
|
*/

beforeEach(function (): void {
    $this->seedPlatform();
});

function resolver(): CommissionResolver
{
    return app(CommissionResolver::class);
}

/**
 * A line to price. Named for this file because Pest shares ONE global function
 * namespace.
 *
 * @param array<int, string> $categoryPath
 */
function subject(
    string $seller = 'seller-1',
    int $baseMinor = 10_000,
    ?string $product = 'product-1',
    ?string $brand = 'brand-1',
    array $categoryPath = ['kozmetik', 'cilt-bakimi'],
): CommissionSubjectDTO {
    return new CommissionSubjectDTO(
        sellerOrgUuid: $seller,
        baseMinor: $baseMinor,
        productUuid: $product,
        brandUuid: $brand,
        categoryPathUuids: $categoryPath,
    );
}

/*
|--------------------------------------------------------------------------
| Most-specific-wins
|--------------------------------------------------------------------------
*/

it('falls back to the platform default when nothing else matches', function (): void {
    // The seeded catch-all — all four scopes null, so it is simply the least
    // specific rule there is rather than a special kind of row.
    $commission = resolver()->resolve(subject(baseMinor: 10_000));

    expect($commission->rate)->toBe('0.1800')
        ->and($commission->amountMinor)->toBe(1_800);
});

it('ranks by how much a rule says about the line, not by how it was written', function (): void {
    CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create();
    CommissionRule::factory()->scoped(['brand_uuid' => 'brand-1'], '0.1000')->create();
    CommissionRule::factory()
        ->scoped(['seller_org_uuid' => 'seller-1', 'category_uuid' => 'kozmetik'], '0.1200')
        ->create();

    /*
     * ALL THREE MATCH THIS LINE, plus the default. The winner sets two scopes; the
     * two single-scope rules and the zero-scope default lose to it regardless of
     * their rates. That ordering is the entire promise of the engine — and note
     * that the WINNER IS NOT THE CHEAPEST OR THE DEAREST, which is what makes this
     * assertion worth having.
     */
    expect(resolver()->resolve(subject())->rate)->toBe('0.1200');
});

it('walks down the specificity ladder as scopes stop matching', function (): void {
    CommissionRule::factory()
        ->scoped(['seller_org_uuid' => 'seller-1', 'category_uuid' => 'kozmetik'], '0.1200')
        ->create();
    CommissionRule::factory()->scoped(['brand_uuid' => 'brand-1'], '0.1000')->create();
    CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create();

    // A different seller: the two-scope rule no longer matches, so the most
    // specific survivor wins — and there are two of one scope each, broken by
    // recency (the brand rule was created first, the category rule second).
    expect(resolver()->resolve(subject(seller: 'seller-2'))->rate)->toBe('0.1500');

    // No brand and a different category: only the default is left.
    expect(resolver()->resolve(subject(seller: 'seller-2', brand: null, categoryPath: ['elektronik']))->rate)
        ->toBe('0.1800');
});

it('uses priority ONLY to break a tie between equally specific rules', function (): void {
    CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create(['priority' => 0]);
    CommissionRule::factory()->scoped(['brand_uuid' => 'brand-1'], '0.1000')->create(['priority' => 50]);

    // Both set one scope, so priority decides.
    expect(resolver()->resolve(subject())->rate)->toBe('0.1000');

    /*
     * AND NOW THE PART THAT MATTERS. A two-scope rule with priority ZERO still
     * beats a one-scope rule with priority FIFTY. A priority that could outrank
     * specificity would make "why did this line get 12%?" unanswerable without
     * simulating the whole engine — the failure mode of every priority-ordered
     * rule system anyone has had to debug.
     */
    CommissionRule::factory()
        ->scoped(['seller_org_uuid' => 'seller-1', 'category_uuid' => 'kozmetik'], '0.1200')
        ->create(['priority' => 0]);

    expect(resolver()->resolve(subject())->rate)->toBe('0.1200');
});

it('ignores a deactivated rule entirely', function (): void {
    CommissionRule::factory()
        ->scoped(['seller_org_uuid' => 'seller-1', 'category_uuid' => 'kozmetik'], '0.0500')
        ->create(['is_active' => false]);

    // `is_active` is the lookup-table convention (ADR-015), and switching a rate
    // off must be as complete as never having written it.
    expect(resolver()->resolve(subject())->rate)->toBe('0.1800');
});

/*
|--------------------------------------------------------------------------
| The category subtree
|--------------------------------------------------------------------------
*/

it('applies a parent category’s rule to everything beneath it', function (): void {
    CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create();

    /*
     * THE LINE IS FILED AT `cilt-bakimi`, TWO LEVELS DOWN. An operator setting a
     * rate on a department means the department — and the test is a membership
     * check against the line's SNAPSHOTTED ancestry, so a product moved in the
     * tree tomorrow cannot change what a sale made today was charged.
     */
    expect(resolver()->resolve(subject(categoryPath: ['kozmetik', 'cilt-bakimi', 'anti-aging']))->rate)
        ->toBe('0.1500');

    // A sibling branch is not beneath it.
    expect(resolver()->resolve(subject(categoryPath: ['elektronik', 'telefon']))->rate)
        ->toBe('0.1800');
});

it('lets the deeper category rule win over its own ancestor’s', function (): void {
    CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create();
    CommissionRule::factory()->scoped(['category_uuid' => 'cilt-bakimi'], '0.1000')->create();

    /*
     * BOTH ARE ONE-SCOPE RULES, so specificity cannot separate them and neither
     * has a priority — recency decides, which is why the more specific category
     * rule must be the one added later. Stated here because it is the engine's
     * one genuinely blunt edge: an operator who adds a department rate AFTER a
     * sub-category rate would override it, and `priority` is the lever for that.
     */
    expect(resolver()->resolve(subject(categoryPath: ['kozmetik', 'cilt-bakimi']))->rate)
        ->toBe('0.1000');
});

it('cannot match a scoped rule when the line has nothing in that dimension', function (): void {
    CommissionRule::factory()->scoped(['brand_uuid' => 'brand-1'], '0.1000')->create();

    // A hand-made product has no brand. It falls through to the default rather
    // than matching a brand rule by accident — and, critically, no empty string
    // ever reaches a `uuid` column on the way (the trap, again).
    expect(resolver()->resolve(subject(brand: null))->rate)->toBe('0.1800');
    expect(resolver()->resolve(subject(brand: null, categoryPath: []))->rate)->toBe('0.1800');
});

/*
|--------------------------------------------------------------------------
| The money
|--------------------------------------------------------------------------
*/

it('computes on the KDV-INCLUSIVE base, in integer kuruş', function (): void {
    /*
     * THE OWNER'S CHOICE (Payment.md §6): commission is a share of the gross the
     * buyer paid, not of the net of tax. 129,90 TL at 18% is 23,38 TL — computed
     * from 12 990 kuruş, never from the ~10 825 kuruş that would be left after
     * extracting KDV.
     */
    expect(resolver()->resolve(subject(baseMinor: 12_990))->amountMinor)->toBe(2_338);
});

it('rounds half-up, once, in one place', function (): void {
    /*
     * ONE ROUNDING RULE, because commission is computed per line at payment and
     * reversed per line on a refund (P5). If those disagreed by a kuruş, a
     * seller's balance would drift by a kuruş per refunded line, forever, with
     * nothing to reconcile it against.
     */
    expect(CommissionAmount::of(1_000, '0.1250'))->toBe(125)
        // 1005 × 0.125 = 125.625 → 126
        ->and(CommissionAmount::of(1_005, '0.1250'))->toBe(126)
        // Exactly .5 goes up: 1004 × 0.125 = 125.5 → 126
        ->and(CommissionAmount::of(1_004, '0.1250'))->toBe(126)
        ->and(CommissionAmount::of(1_003, '0.1250'))->toBe(125);
});

it('never returns a negative or nonsensical commission', function (): void {
    // %0 is a real arrangement — a launch promotion, a strategic seller — and the
    // platform paying the seller EXTRA is not.
    expect(CommissionAmount::of(10_000, '0.0000'))->toBe(0)
        ->and(CommissionAmount::of(10_000, ''))->toBe(0)
        ->and(CommissionAmount::of(0, '0.1800'))->toBe(0)
        ->and(CommissionAmount::of(-5_000, '0.1800'))->toBe(0);
});

it('takes nothing at all when the platform has configured no rules', function (): void {
    CommissionRule::query()->delete();

    /*
     * NOT AN ERROR. A platform that has not decided its rates takes no commission,
     * which is the correct behaviour and what lets this module ship before the
     * owner has settled on numbers. Throwing here would make a payment fail over
     * a configuration gap.
     */
    $commission = resolver()->resolve(subject(baseMinor: 10_000));

    expect($commission->rate)->toBe('0.0000')
        ->and($commission->amountMinor)->toBe(0)
        ->and($commission->ruleUuid)->toBeNull();
});

it('names the rule that won, for the audit trail', function (): void {
    $rule = CommissionRule::factory()->scoped(['category_uuid' => 'kozmetik'], '0.1500')->create();

    // "Why 15%?" must point at a row, not at an algorithm.
    expect(resolver()->resolve(subject())->ruleUuid)->toBe($rule->uuid);
});
