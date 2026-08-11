<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferFeedOutcome;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;

/**
 * Stock, and only stock — the call a seller makes every hour (ADR-076).
 *
 * **IT REFUSES TO CREATE AN OFFER, WHICH IS THE WHOLE REASON IT IS SEPARATE.**
 * There is no price in a stock item, so an offer conjured from one would have to
 * invent the number a buyer pays. "Run sync first" is the honest answer, and it is
 * a machine reason (`offer_not_found`) rather than a silent no-op — silence would
 * let a seller believe a thousand SKUs were live when none of them were.
 *
 * A NO-CHANGE PUSH EMITS NOTHING. The hourly feed mostly repeats yesterday, and
 * `OfferStockChanged` is what Inventory mirrors (ADR-048) — re-announcing an
 * unchanged number would be work for its own sake, four thousand times an hour.
 *
 * @see SyncSellerOfferAction
 */
final class SyncSellerStockAction extends BaseFeedAction
{
    public function __construct(
        private readonly CatalogQueryContract $catalog,
        private readonly OfferRepositoryContract $offers,
        private readonly UpdateOfferStockAction $updateStock,
    ) {}

    public function handle(mixed ...$arguments): OfferFeedOutcome
    {
        /** @var SyncOfferDTO $data */
        $data = $arguments[0];

        if ($data->stockQuantity === null || $data->stockQuantity < 0) {
            throw OfferFeedException::invalidStock($data->gtin);
        }

        $variantUuid = $this->resolveVariant($this->catalog, $data->gtin);
        $offer = $this->offers->duplicateFor($data->sellingOrgId, $variantUuid);

        if ($offer === null) {
            throw OfferFeedException::offerNotFound($data->gtin);
        }

        if ($data->stockQuantity === $offer->stock_quantity) {
            return OfferFeedOutcome::Unchanged;
        }

        $this->updateStock->run($offer, new UpdateOfferStockDTO(
            stockQuantity: $data->stockQuantity,
            reason: self::REASON,
        ));

        return OfferFeedOutcome::Updated;
    }
}
