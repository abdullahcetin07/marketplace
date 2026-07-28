<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Contracts\BrandRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategoryRepositoryContract;
use App\Modules\Catalog\Domain\Contracts\CategorySlugGeneratorContract;
use App\Modules\Catalog\Domain\Contracts\ProductRepositoryContract;
use App\Modules\Catalog\Domain\DTOs\DraftProductDTO;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductDrafted;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * Opens a product proposal — the seller's "ürün aç" (§5, entry point 2).
 *
 * WHAT IS CHECKED HERE, AND WHAT IS NOT. Two rules bind at draft time because
 * violating them means the draft is unusable from the start:
 *
 *   * The category must be a LEAF (§3.2). Attaching to a container would leave
 *     the product with no attribute schema to satisfy and nowhere sensible in
 *     the tree.
 *   * A supplied GTIN must be free (§3.4). This is the shared catalog's dedup
 *     key, and telling a seller "that product is already here, offer it" at the
 *     moment they type the barcode is the entire point — discovering it at
 *     publish, after they have filled in a form and uploaded photographs, is
 *     the version that loses merchants.
 *
 * REQUIRED ATTRIBUTES ARE NOT CHECKED HERE, deliberately (§3.2): authoring is
 * incremental, and a draft that refuses to save until every field is filled is
 * a form nobody can leave and come back to. That check is PublishProductAction's.
 *
 * NO PRICE AND NO STOCK (ADR-037). The DTO has no such fields; if a form is
 * sending them, it is an Offer form.
 */
final class DraftProductAction extends BaseAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
        private readonly BrandRepositoryContract $brands,
        private readonly ProductRepositoryContract $products,
        private readonly CategorySlugGeneratorContract $slugs,
    ) {}

    public function handle(mixed ...$arguments): Product
    {
        /** @var DraftProductDTO $data */
        $data = $arguments[0];

        $category = $this->categories->findOrFailByUuid($data->categoryUuid);

        if (! $category->isLeaf()) {
            throw CatalogException::categoryIsNotALeaf($category->uuid);
        }

        $gtin = $this->normaliseGtin($data->gtin);

        if ($gtin !== null) {
            $existing = $this->products->findByGtin($gtin);

            if ($existing !== null) {
                throw CatalogException::gtinAlreadyInCatalog($gtin, $existing->uuid);
            }
        }

        $requested = $data->slug ?? ($data->title[config('catalog.locales')[0] ?? 'tr'] ?? '');

        $product = new Product;
        $product->fill([
            'category_id' => $category->getKey(),
            'brand_id' => $this->brandId($data),
            'slug' => $this->slugs->forProduct((string) $requested),
            'gtin' => $gtin,
            'status' => ProductStatus::Draft,
            'proposed_by_org_id' => $data->proposedByOrgId,
            'proposed_by_org_uuid' => $data->proposedByOrgUuid,
            'proposed_by_user_id' => $data->proposedByUserId,
        ]);
        $product->fillLocalized('title', $data->title);
        $product->fillLocalized('description', $data->description);
        $product->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductDrafted::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->category_id,
            $result->proposed_by_org_uuid,
        );
    }

    /**
     * A blank barcode field posts an empty string, which must not be stored as
     * a GTIN — the column is UNIQUE, so the second product with `''` would fail
     * on an index nobody meant to hit.
     */
    private function normaliseGtin(?string $gtin): ?string
    {
        $gtin = $gtin === null ? null : trim($gtin);

        return $gtin === '' ? null : $gtin;
    }

    private function brandId(DraftProductDTO $data): ?int
    {
        if ($data->brandUuid === null) {
            return null;
        }

        return (int) $this->brands->findOrFailByUuid($data->brandUuid)->getKey();
    }
}
