<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Core\Domain\Events\BaseEvent;

/**
 * A product proposal was opened — the seller's "ürün aç" (§5, entry point 2).
 *
 * A Draft is not in the catalog yet in any meaningful sense; this exists so the
 * provenance trail starts at authoring rather than at submission, and so a
 * seller's abandoned drafts are visible in the record.
 *
 * `proposedByOrgUuid` is null when staff authored the product directly.
 *
 * @see docs/modules/Catalog.md §7
 */
final class ProductDrafted extends BaseEvent
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productUuid,
        public readonly int $categoryId,
        public readonly ?string $proposedByOrgUuid,
    ) {
        parent::__construct();
    }
}
