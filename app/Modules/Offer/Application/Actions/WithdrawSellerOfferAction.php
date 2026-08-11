<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\Enums\OfferFeedOutcome;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;

/**
 * Take an offer off sale from the feed (ADR-076).
 *
 * **WITHDRAWING IS NOT DELETING**, and the feed does not get a stronger verb than
 * the panel has: `WithdrawOfferAction` owns the transition and its rules, this only
 * finds the offer a barcode names.
 *
 * **ALREADY WITHDRAWN IS `Unchanged`, NOT AN ERROR.** A seller re-sending yesterday's
 * discontinued list must not receive a page of failures for work already done —
 * that is the same idempotency every other feed call promises.
 *
 * @see SyncSellerOfferAction
 */
final class WithdrawSellerOfferAction extends BaseFeedAction
{
    public function __construct(
        private readonly CatalogQueryContract $catalog,
        private readonly OfferRepositoryContract $offers,
        private readonly WithdrawOfferAction $withdraw,
    ) {}

    public function handle(mixed ...$arguments): OfferFeedOutcome
    {
        /** @var SyncOfferDTO $data */
        $data = $arguments[0];

        $variantUuid = $this->resolveVariant($this->catalog, $data->gtin);
        /*
        | **NOT `duplicateFor()`, AND THAT IS THE WHOLE SUBTLETY HERE.** Withdrawing
        | is a SOFT DELETE (@see `WithdrawOfferAction`), and `duplicateFor()` asks
        | "may this seller list?" — so it cannot see a withdrawn offer, by design,
        | or a seller could never re-list a product they once dropped. Asking it
        | here would make every repeat withdrawal an `offer_not_found` failure, and
        | a seller re-sending yesterday's discontinued list would get a page of
        | errors for work already done.
        */
        $offer = $this->offers->anyForSellerAndVariant($data->sellingOrgId, $variantUuid);

        if ($offer === null) {
            throw OfferFeedException::offerNotFound($data->gtin);
        }

        if ($offer->status === OfferStatus::Withdrawn) {
            return OfferFeedOutcome::Unchanged;
        }

        $this->withdraw->run($offer, self::REASON);

        return OfferFeedOutcome::Updated;
    }
}
