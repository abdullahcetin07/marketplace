<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Core\Domain\Contracts\OfferQueryContract;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `products.is_sellable` in step with the offers behind it (ADR-079).
 *
 * **THE FACT BELONGS TO THREE MODULES AND THE COLUMN BELONGS TO ONE.** Whether a
 * product can be bought depends on an active offer (Offer), a live store (Store)
 * and stock that is not all reserved (Inventory). Catalog owns `products`, may
 * import none of them, and asks the single question it is allowed to ask:
 * `OfferQueryContract::sellableProductUuids()`, narrowed to the products that just
 * changed.
 *
 * **NARROWED IS THE WHOLE POINT.** The same call over the whole catalogue is what
 * the browse used to do on every request; asked about one product it is an indexed
 * lookup. A listener recomputes one product, the sweep recomputes everything, and
 * both go through here so there is one definition of the answer.
 *
 * **IT IS A CACHE AND IT IS ALLOWED TO DRIFT.** Sellability changes for reasons no
 * event announces to Catalog — a store going dark, a reservation expiring — so
 * `catalog:refresh-sellability` rebuilds it on a schedule and drift heals itself.
 * A flag that is briefly stale shows a product one sweep late; a flag that could
 * never be rebuilt would need a migration to correct.
 *
 * @see App\Modules\Catalog\Application\Listeners\RefreshProductSellability
 */
final class ProductSellability
{
    public function __construct(private readonly OfferQueryContract $offers) {}

    /**
     * Recompute the flag for these products.
     *
     * @param array<int, string> $productUuids
     */
    public function refresh(array $productUuids): void
    {
        $productUuids = array_values(array_unique(array_filter($productUuids)));

        if ($productUuids === []) {
            return;
        }

        $sellable = $this->offers->sellableProductUuids($productUuids);

        /*
        | TWO WRITES, NOT ONE PER PRODUCT, and only where the value actually
        | differs — a listener that fires on every stock movement would otherwise
        | write an `updated_at` for a fact that did not change, and every one of
        | those is a search-index reindex behind it.
        */
        if ($sellable !== []) {
            Product::query()->whereIn('uuid', $sellable)->where('is_sellable', false)
                ->update(['is_sellable' => true]);
        }

        $unsellable = array_values(array_diff($productUuids, $sellable));

        if ($unsellable !== []) {
            Product::query()->whereIn('uuid', $unsellable)->where('is_sellable', true)
                ->update(['is_sellable' => false]);
        }
    }

    /**
     * The products a variant belongs to — the bridge Inventory's events need.
     *
     * Inventory speaks (org, variant) because that is what a stock pool is
     * (ADR-051); the browse filters products. Catalog owns variants, so the
     * translation is one indexed read here rather than a column Inventory would
     * have to carry about somebody else's model.
     *
     * @param array<int, string> $variantUuids
     *
     * @return array<int, string>
     */
    public function productsOfVariants(array $variantUuids): array
    {
        if ($variantUuids === []) {
            return [];
        }

        /** @var array<int, string> $uuids */
        $uuids = DB::table('product_variants')
            ->whereIn('product_variants.uuid', $variantUuids)
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->distinct()
            ->pluck('products.uuid')
            ->all();

        return $uuids;
    }

    /**
     * Rebuild the flag for the whole catalogue.
     *
     * **ONE PASS OVER THE PRODUCTS, ONE SELLABLE READ.** Chunking per product
     * would reintroduce the per-row cost this column exists to remove.
     *
     * @return array{sellable: int, changed: int}
     */
    public function rebuild(): array
    {
        $sellable = $this->offers->sellableProductUuids();

        $changed = 0;

        foreach (array_chunk($sellable, 5_000) as $chunk) {
            $changed += Product::query()->whereIn('uuid', $chunk)->where('is_sellable', false)
                ->update(['is_sellable' => true]);
        }

        /*
        | THE COMPLEMENT IN SQL, not in PHP: "every product that is flagged and is
        | not in the sellable set" over a catalogue of twenty thousand is a
        | `whereNotIn` the database can answer, and materialising the difference
        | here would be the memory version of the same mistake.
        */
        $flagged = DB::table('products')->where('is_sellable', true);

        foreach (array_chunk($sellable, 5_000) as $chunk) {
            $flagged->whereNotIn('uuid', $chunk);
        }

        $changed += $flagged->update(['is_sellable' => false]);

        return ['sellable' => count($sellable), 'changed' => $changed];
    }
}
