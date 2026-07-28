<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Exceptions\CatalogException;
use App\Modules\Catalog\Domain\Models\Product;

/**
 * Delists a product — the terminal state (§3.1/§3.5).
 *
 * NEVER A HARD DELETE. Offers will reference this product, and an order history
 * pointing at a row that no longer exists is unreadable. `Archived` is the
 * business end-state; `deleted_at` remains the separate, recoverable removal.
 *
 * Search drops the document on the resulting event (§10) — which is the whole
 * observable effect of archiving, since nothing else reads a non-published
 * product.
 */
final class ArchiveProductAction extends BaseAction
{
    public function handle(mixed ...$arguments): Product
    {
        /** @var Product $product */
        $product = $arguments[0];

        if (! $product->status->canTransitionTo(ProductStatus::Archived)) {
            throw CatalogException::invalidTransition($product->status, ProductStatus::Archived);
        }

        $product->forceFill(['status' => ProductStatus::Archived])->save();

        return $product;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Product $result */
        ProductArchived::dispatch($result->getKey(), $result->uuid, $result->proposed_by_org_uuid);
    }
}
