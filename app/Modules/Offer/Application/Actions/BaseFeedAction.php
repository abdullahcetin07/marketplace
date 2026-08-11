<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;

/**
 * What the three feed actions share (ADR-076).
 *
 * **THE GTIN LOOKUP AND THE AUDIT REASON, AND NOTHING ELSE.** Sync, stock-only and
 * withdraw each own their own decision; what they must not own separately is how a
 * barcode becomes a variant, because three copies of that would be three chances
 * for one of them to stop rejecting an unpublished product.
 *
 * @see SyncSellerOfferAction
 */
abstract class BaseFeedAction extends BaseAction
{
    /**
     * Written into the audit trail beside every change the feed makes, so a
     * seller reading their own history can tell an API push from somebody
     * typing in the panel.
     */
    protected const string REASON = 'Satıcı akışı (API/CSV)';

    /**
     * A barcode → the published product's sellable variant.
     *
     * **UNKNOWN AND UNPUBLISHED ANSWER ALIKE** (@see `CatalogQueryContract`): the
     * seller's next move is the same either way — ask the platform to add the
     * product — and distinguishing them would let a feed enumerate the
     * unpublished catalogue one barcode at a time.
     */
    protected function resolveVariant(CatalogQueryContract $catalog, string $gtin): string
    {
        $variantUuid = $catalog->publishedVariantUuidForGtin($gtin);

        if ($variantUuid === null) {
            throw OfferFeedException::productNotInCatalog($gtin);
        }

        return $variantUuid;
    }
}
