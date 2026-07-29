<?php

declare(strict_types=1);

namespace App\Modules\Offer\Infrastructure\Queries;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * THE BUY BOX (§5, ADR-045) — and Offer's implementation of the downstream read
 * port.
 *
 * COMPUTED ON EVERY READ, NEVER STORED. There is no winning-offer column and no
 * ranking job: the featured offer is the cheapest `Active`, in-stock offer on an
 * Active store, ties broken by earliest `created_at`. The partial index
 * `offers_buy_box` holds exactly the rows that can win, in exactly that order,
 * which is what makes recomputing cheaper than invalidating a stored winner on
 * every competing offer's price change.
 *
 * THE THIRD ELIGIBILITY CONDITION IS CROSS-CONTEXT. Status and stock are Offer's
 * own columns; "is the seller's store live" belongs to Store, and Offer may not
 * join `stores` or import the model (ADR-033/046). So the SQL applies the two
 * conditions it owns, and `StoreQueryContract` answers the third — memoised per
 * instance, because a product page asks about as many stores as it has sellers
 * and asking twice for the same one is waste, not correctness.
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
            ->sellable()
            ->with('currency')
            // Cheapest first; ties by earliest created_at — a stable,
            // explainable rule a seller can be told (§5). Both columns are in
            // the partial index, in this order, so there is no sort step.
            ->orderBy('price_minor')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($offers as $offer) {
            if ($filterByStore && ! $this->storeIsLive($offer->store_uuid)) {
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
            'stock_quantity' => $offer->stock_quantity,
            'in_stock' => $offer->isInStock(),
            'created_at' => $offer->created_at->toIso8601String(),
        ];
    }
}
