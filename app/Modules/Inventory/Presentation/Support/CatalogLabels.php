<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Support;

use App\Core\Domain\Contracts\CatalogBrowseContract;

/**
 * What a stock pool's uuids are CALLED — resolved once per request.
 *
 * A stock pool stores `product_uuid` and `variant_uuid` and nothing else about
 * the thing it counts (ADR-040). That is the right storage decision and a
 * terrible one to render directly: a seller looking at their stock needs
 * "Pamuklu Tişört — Kırmızı / M", not two uuids. The alternative — copying the
 * title onto the stock row — is precisely what ADR-037 refuses, because a title
 * edited in the catalog would then disagree with every stale copy, forever.
 *
 * A NEAR-DUPLICATE OF OFFER'S CLASS OF THE SAME NAME, and deliberately not
 * shared: promoting it to Core would make Core depend on a module's need, and
 * having Inventory import Offer's copy is the boundary this module exists inside.
 * Two small readers over one Core contract is the cheaper of the two wrongs, and
 * the duplication is ~60 lines of memoisation with no business rule in it.
 *
 * So the labels are fetched, and this memoises them for the life of one request
 * so a table of twenty offers by the same seller does not ask twenty times.
 *
 * THE COST, stated plainly: the first row referencing an unseen product costs
 * one query. A page of N offers over N distinct products is N queries — bounded
 * by the page size, deduped across rows, and against an indexed uuid lookup.
 * `prime()` collapses that to one query when the caller can hand over the whole
 * page's uuids up front, which the resources below do.
 *
 * Presentation-only by design: nothing here is a business rule, and no other
 * layer may depend on it.
 */
final class CatalogLabels
{
    /** @var array<string, array{uuid: string, title: string, brand: string|null, category: string}> */
    private array $products = [];

    /** @var array<string, array{uuid: string, product_uuid: string, sku: string, label: string}> */
    private array $variants = [];

    /** @var array<string, true> Uuids already asked about, hit or miss. */
    private array $askedProducts = [];

    /** @var array<string, true> */
    private array $askedVariants = [];

    public function __construct(
        private readonly CatalogBrowseContract $catalog,
    ) {}

    /**
     * Resolve a whole page in one round trip. Call it before rendering rows.
     *
     * @param array<int, string> $productUuids
     * @param array<int, string> $variantUuids
     */
    public function prime(array $productUuids, array $variantUuids = []): void
    {
        $products = $this->unasked($productUuids, $this->askedProducts);
        $variants = $this->unasked($variantUuids, $this->askedVariants);

        if ($products !== []) {
            $this->products += $this->catalog->productSummaries($products);
        }

        if ($variants !== []) {
            $this->variants += $this->catalog->variantSummaries($variants);
        }
    }

    /**
     * The product's title, or a truncated uuid when the catalog no longer
     * publishes it — an archived product's offers are paused, not deleted, so a
     * seller may well be looking at one (§3.5).
     */
    public function productTitle(string $productUuid): string
    {
        $this->prime([$productUuid]);

        return $this->products[$productUuid]['title'] ?? $this->fallback($productUuid);
    }

    public function productBrand(string $productUuid): ?string
    {
        $this->prime([$productUuid]);

        return $this->products[$productUuid]['brand'] ?? null;
    }

    /**
     * "Kırmızı / M", or the SKU for a product with no variant axes.
     */
    public function variantLabel(string $variantUuid): string
    {
        $this->prime([], [$variantUuid]);

        return $this->variants[$variantUuid]['label'] ?? $this->fallback($variantUuid);
    }

    public function variantSku(string $variantUuid): ?string
    {
        $this->prime([], [$variantUuid]);

        return $this->variants[$variantUuid]['sku'] ?? null;
    }

    /**
     * Uuids not yet asked about, marked as asked. Tracking the QUESTION rather
     * than the answer is what stops a missing product being re-queried on every
     * row that references it.
     *
     * @param array<int, string> $uuids
     * @param array<string, true> $asked
     *
     * @return array<int, string>
     */
    private function unasked(array $uuids, array &$asked): array
    {
        $fresh = [];

        foreach (array_unique(array_filter($uuids)) as $uuid) {
            if (! isset($asked[$uuid])) {
                $asked[$uuid] = true;
                $fresh[] = $uuid;
            }
        }

        return $fresh;
    }

    /**
     * Short and obviously an identifier, so a missing label reads as "the
     * catalog does not publish this" rather than as a broken title.
     */
    private function fallback(string $uuid): string
    {
        return '#'.mb_substr($uuid, 0, 8);
    }
}
