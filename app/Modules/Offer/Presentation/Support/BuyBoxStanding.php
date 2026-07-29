<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Support;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * Where a seller's offer stands against everyone else's, for one variant.
 *
 * WHAT THE SELLER ACTUALLY WANTS TO KNOW: am I winning, and if not, what do I
 * have to beat? Two numbers, both COMPUTED on read — there is no stored rank and
 * no stored winner (ADR-045), and adding one here would be the cache-coherency
 * problem that decision was taken to avoid.
 *
 * IT ASKS `OfferQueryContract`, not the table. That contract already answers
 * "who is eligible, cheapest first, ties by created_at" — including the
 * cross-context condition that the seller's store must be live — so reading the
 * rank from it means the seller's list and the buyer's product page can never
 * disagree about who is winning. A second ordering here would eventually drift
 * from the real one, and the seller would be told they are first while a buyer
 * sees someone else featured.
 *
 * MEMOISED PER REQUEST, per variant. A page of twenty offers over twenty
 * variants is twenty queries; twenty offers on the SAME variant is one. The
 * memo lives exactly as long as one render.
 *
 * NO COMMISSION COLUMN. What the platform takes is settled at Order/Payment
 * time and has no source of truth yet (ADR-042 §0.2) — a number invented here
 * would be a guess a seller prices against.
 */
final class BuyBoxStanding
{
    /**
     * Eligible offers per variant uuid, cheapest first.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $byVariant = [];

    public function __construct(
        private readonly OfferQueryContract $offers,
    ) {}

    /**
     * This offer's position among the variant's eligible offers, 1-based; null
     * when it is not competing at all.
     *
     * Paused, suspended and out-of-stock offers return null rather than a large
     * number: "you are 7th" and "you are not in the running" are different
     * facts, and rendering the first for the second would tell a seller to drop
     * their price when what they need is to restock.
     */
    public function rank(Offer $offer): ?int
    {
        if (! $offer->isBuyBoxEligible()) {
            return null;
        }

        foreach ($this->eligibleFor($offer->variant_uuid) as $index => $row) {
            if ($row['uuid'] === $offer->uuid) {
                return $index + 1;
            }
        }

        /*
         * Eligible by its own columns but absent from the contract's answer —
         * which happens when the seller's STORE is not live. That is a real
         * state, not a bug: the offer is fine and the shop is dark.
         */
        return null;
    }

    /**
     * The price currently winning this variant, in minor units; null when
     * nobody is selling it.
     */
    public function winningPriceMinor(string $variantUuid): ?int
    {
        $winner = $this->eligibleFor($variantUuid)[0] ?? null;

        return $winner === null ? null : (int) $winner['price_minor'];
    }

    /**
     * How many sellers are competing for this variant right now — the context
     * that makes a rank mean something. "3rd" says little; "3rd of 3" says a
     * lot.
     */
    public function competitorCount(string $variantUuid): int
    {
        return count($this->eligibleFor($variantUuid));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eligibleFor(string $variantUuid): array
    {
        return $this->byVariant[$variantUuid] ??= $this->offers->activeOffersForVariant($variantUuid);
    }
}
