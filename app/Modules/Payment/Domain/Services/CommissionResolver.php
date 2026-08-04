<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Services;

use App\Modules\Payment\Domain\DTOs\CommissionDTO;
use App\Modules\Payment\Domain\DTOs\CommissionSubjectDTO;
use App\Modules\Payment\Domain\Models\CommissionRule;
use App\Modules\Payment\Domain\Support\CommissionAmount;
use Illuminate\Support\Collection;

/**
 * Which rule applies to this line, and what it comes to (ADR-061, Payment.md §6).
 *
 * MOST-SPECIFIC-WINS, AND THAT IS THE WHOLE ALGORITHM. Every active rule whose
 * non-null scopes all match is a candidate; the one that sets the MOST scopes
 * wins. "Seller X + Kozmetik" (2) beats "brand Bioderma" (1) beats "Kozmetik" (1,
 * broken by priority) beats the default (0).
 *
 * WHY SPECIFICITY OUTRANKS `priority`, which is the decision worth defending. A
 * priority that could beat specificity makes "why did this line get 12%?"
 * unanswerable without reading every row and simulating the engine — the failure
 * mode of every priority-ordered rule system anyone has had to debug. Here the
 * answer is always one sentence: *the most specific rule that matched*. `priority`
 * exists only to break a tie between rules of EQUAL specificity, where there is
 * genuinely nothing else to go on.
 *
 * A CATEGORY RULE COVERS THE SUBTREE. A rate on "Kozmetik" applies to "Cilt
 * Bakımı" beneath it, because an operator setting a department rate means the
 * department. That is matched against the line's SNAPSHOTTED ancestry, not the
 * live tree: a product re-categorised next month must not change which rule
 * applied to a sale already made.
 *
 * IT RANKS IN PHP, NOT IN SQL, deliberately. The candidate set is "active rules
 * that could match these four uuids" — a handful of rows even on a large platform
 * — and specificity-then-priority-then-recency is not a comparison a database
 * expresses without a CASE ladder that nobody can read. Fetch narrow, rank
 * legibly.
 *
 * IT ALWAYS RETURNS SOMETHING. With no rules at all it returns a zero-rate
 * commission rather than throwing: a platform that has not configured commission
 * yet takes none, which is the correct behaviour and lets the module ship before
 * the owner has decided their rates.
 *
 * A DOMAIN SERVICE, not an action: it computes and writes nothing (ADR-010's
 * "several methods, no transaction of its own" — here, one question asked twice).
 *
 * @see docs/modules/Payment.md §6
 */
final class CommissionResolver
{
    /**
     * The commission for one order line.
     */
    public function resolve(CommissionSubjectDTO $subject): CommissionDTO
    {
        $rule = $this->ruleFor($subject);

        // Zero when the platform has configured no rules at all: it then takes
        // nothing, which lets the module ship before the owner has decided rates.
        $rate = $rule === null ? '0.0000' : (string) $rule->rate;

        return new CommissionDTO(
            ruleUuid: $rule?->uuid,
            rate: $rate,
            // KDV-INCLUSIVE base (owner choice, Payment.md §6): the gross the
            // buyer paid, not the net of tax.
            amountMinor: CommissionAmount::of($subject->baseMinor, $rate),
        );
    }

    /**
     * The winning rule, or null when the platform has configured none.
     */
    public function ruleFor(CommissionSubjectDTO $subject): ?CommissionRule
    {
        return $this->candidates($subject)
            ->sort(function (CommissionRule $a, CommissionRule $b): int {
                // 1. Specificity — the rule that says the most about this line.
                return $b->specificity() <=> $a->specificity()
                    // 2. Explicit priority, only between equals.
                    ?: $b->priority <=> $a->priority
                    // 3. The most recently created, so re-adding a rule to
                    //    supersede an identical one works without a priority.
                    ?: $b->getKey() <=> $a->getKey();
            })
            ->first();
    }

    /**
     * Every active rule whose non-null scopes all match this line.
     *
     * NARROWED IN SQL, including the subtree: the line's ancestry is already an
     * array of uuids, so "a rule on any ancestor" is a plain `whereIn` and needs
     * no driver-specific JSON operator — which matters, because this platform runs
     * two engines.
     *
     * @return Collection<int, CommissionRule>
     */
    private function candidates(CommissionSubjectDTO $subject): Collection
    {
        $query = CommissionRule::query()->active();

        /*
        | A NULL SCOPE IS A WILDCARD AND ALWAYS MATCHES; a set scope must equal
        | the line's. Four independent narrowings, one per dimension — and each
        | one takes the "wildcards only" shape when the line has nothing to match
        | with, because a line with no brand cannot match a brand-scoped rule.
        |
        | THE NULL HANDLING IS NOT DEFENSIVE POLISH. These are `uuid` columns on
        | PostgreSQL, and comparing one to an empty string is SQLSTATE[22P02] — a
        | 500, not a non-match — which is the trap this platform has met five
        | times. A value that is not a uuid never reaches the column.
        */
        $this->narrow($query, 'seller_org_uuid', [$subject->sellerOrgUuid]);
        $this->narrow($query, 'product_uuid', [$subject->productUuid]);
        $this->narrow($query, 'brand_uuid', [$subject->brandUuid]);
        // The subtree test: a rule on any ancestor — or on the category itself —
        // applies to this line.
        $this->narrow($query, 'category_uuid', $subject->categoryPathUuids);

        /** @var Collection<int, CommissionRule> $rules */
        $rules = $query->get();

        return $rules;
    }

    /**
     * "Rules whose `$column` is null, or is one of `$values`."
     *
     * With no usable values it collapses to "null only" — a line that has no
     * brand can be covered by a wildcard rule and by nothing else.
     *
     * @param \Illuminate\Database\Eloquent\Builder<CommissionRule> $query
     * @param array<int, string|null> $values
     */
    private function narrow(mixed $query, string $column, array $values): void
    {
        $usable = array_values(array_filter($values, static fn (?string $v): bool => $v !== null && $v !== ''));

        $query->where(function ($inner) use ($column, $usable): void {
            $inner->whereNull($column);

            if ($usable !== []) {
                $inner->orWhereIn($column, $usable);
            }
        });
    }
}
