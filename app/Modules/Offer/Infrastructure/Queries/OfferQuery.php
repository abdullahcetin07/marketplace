<?php

declare(strict_types=1);

namespace App\Modules\Offer\Infrastructure\Queries;

use App\Core\Domain\Contracts\InventoryQueryContract;
use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * THE BUY BOX (§5, ADR-045) — and Offer's implementation of the downstream read
 * port.
 *
 * COMPUTED ON EVERY READ, NEVER STORED. There is no winning-offer column and no
 * ranking job: the featured offer is the cheapest `Active`, AVAILABLE offer on an
 * Active store, ties broken by earliest `created_at`. Recomputing is cheaper than
 * invalidating a stored winner on every competing offer's price change.
 *
 * TWO OF THE THREE CONDITIONS ARE CROSS-CONTEXT, and only `status` is Offer's own:
 *
 *   - "is the seller's store live" belongs to Store, answered through
 *     `StoreQueryContract` and memoised per instance — a product page asks about
 *     as many stores as it has sellers, and asking twice for one is waste.
 *   - "can this actually be sold" belongs to INVENTORY since ADR-048, answered
 *     through `InventoryQueryContract`. `Offer.stock_quantity` is what the seller
 *     DECLARED; availability is `on_hand − reserved`, and five in the warehouse
 *     with five held for other people's checkouts is not sellable.
 *
 * Availability is NOT memoised, deliberately, unlike store liveness: it is the
 * number a checkout is about to act on, and a value cached even for one request
 * could tell two callers the same unit is free.
 *
 * WHY MEMOISATION RATHER THAN A CACHE. `cache()` is forbidden in a Domain layer
 * (ADR-019) and would be wrong here anyway: a suspended store must disappear
 * from the buy box on the next request, not when a TTL expires. A per-instance
 * array lives exactly as long as one read.
 *
 * RETURNS PLAIN ARRAYS, never models — the boundary rule every Core query
 * contract follows. Money crosses as `price_minor` plus a currency code;
 * rendering it as a decimal string is the caller's presentation concern.
 *
 * @see App\Core\Domain\Contracts\OfferQueryContract
 * @see docs/modules/Offer.md §5, §8.1
 */
final class OfferQuery implements OfferQueryContract
{
    /**
     * Store liveness answered so far in this read. @see the class docblock.
     *
     * @var array<string, bool>
     */
    private array $liveStores = [];

    public function __construct(
        private readonly StoreQueryContract $stores,
        private readonly InventoryQueryContract $inventory,
    ) {}

    public function offerExists(string $offerUuid): bool
    {
        // Withdrawn offers are soft-deleted, so the default scope already
        // excludes them: the row survives for a future order line, but nothing
        // may start selling from it again.
        return Offer::query()->where('uuid', $offerUuid)->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeOffersForProduct(string $productUuid): array
    {
        return $this->eligible(
            Offer::query()->forProduct($productUuid),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function featuredOfferForProduct(string $productUuid): ?array
    {
        // The winner is the first eligible row, so this IS the list question
        // asked once. Deliberately not a separate `LIMIT 1` query: that would
        // return the cheapest offer whose store might be suspended, and the
        // product page would feature nothing while other sellers were live.
        return $this->activeOffersForProduct($productUuid)[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeOffersForVariant(string $variantUuid): array
    {
        return $this->eligible(
            Offer::query()->forVariant($variantUuid),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function offersForStore(string $storeUuid): array
    {
        // The store is the subject here, so its liveness is the caller's
        // premise, not a filter — the storefront only assembles pages for a
        // live store in the first place (ADR-034).
        return $this->eligible(
            Offer::query()->where('store_uuid', $storeUuid),
            filterByStore: false,
        );
    }

    /**
     * The buy-box ordering and the eligibility rule, in one place so the
     * featured offer and the seller list can never disagree about who wins.
     *
     * @param  Builder<Offer>  $builder
     * @return array<int, array<string, mixed>>
     */
    private function eligible(Builder $builder, bool $filterByStore = true): array
    {
        /** @var Collection<int, Offer> $offers */
        $offers = $builder
            /*
            | STATUS ONLY IN SQL NOW. The in-stock half moved to Inventory
            | (ADR-048): `Offer.stock_quantity` is what the seller DECLARED, and
            | availability is `on_hand − reserved`, which only Inventory knows.
            | A variant with five in the warehouse and five held for other
            | people's checkouts is not sellable, and no column here can say so.
            |
            | Cost: `offers_buy_box` is a PARTIAL index predicated on
            | `stock_quantity > 0`, so dropping that filter stops it matching. A
            | plain index on the same columns would serve this query — recorded
            | as a follow-up rather than added here, because it is a performance
            | change and this phase is a correctness one.
            */
            ->where('status', OfferStatus::Active->value)
            ->with('currency')
            // Cheapest first; ties by earliest created_at — a stable,
            // explainable rule a seller can be told (§5).
            ->orderBy('price_minor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($offers as $offer) {
            if ($filterByStore && ! $this->storeIsLive($offer->store_uuid)) {
                continue;
            }

            // THE AUTHORITATIVE IN-STOCK TEST (ADR-048). Read per offer rather
            // than filtered in SQL, because Inventory is a different bounded
            // context and Offer may not join its table.
            if (! $this->inventory->isAvailable($offer->variant_uuid, $offer->selling_org_uuid)) {
                continue;
            }

            $rows[] = $this->toRow($offer);
        }

        return $rows;
    }

    private function storeIsLive(string $storeUuid): bool
    {
        return $this->liveStores[$storeUuid] ??= $this->stores->isLive($storeUuid);
    }

    /**
     * The contract's row shape. Internal ids are absent by construction — this
     * payload reaches a public product page (non-negotiable #7).
     *
     * @return array<string, mixed>
     */
    private function toRow(Offer $offer): array
    {
        return [
            'uuid' => $offer->uuid,
            'variant_uuid' => $offer->variant_uuid,
            'product_uuid' => $offer->product_uuid,
            'selling_org_uuid' => $offer->selling_org_uuid,
            'store_uuid' => $offer->store_uuid,
            'price_minor' => $offer->price_minor,
            'list_price_minor' => $offer->list_price_minor,
            'currency_code' => $offer->currency->code,
            // The seller's DECLARED quantity, kept for the seller-facing
            // surfaces that show what they typed.
            'stock_quantity' => $offer->stock_quantity,
            /*
            | Every row in this list already passed the Inventory check above, so
            | this is true by construction. Stated as a literal rather than as
            | `$offer->isInStock()`, which would read the Offer's own column and
            | could contradict the filter that let the row through.
            */
            'in_stock' => true,
            'created_at' => $offer->created_at->toIso8601String(),
        ];
    }
}
