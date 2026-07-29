<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;

/**
 * A product left the catalog, so nothing may keep selling it (§3.5).
 *
 * PAUSED, NOT WITHDRAWN, and that is the whole decision. Archiving is often
 * reversible — a listing pulled for a photo problem, a category reshuffle, a
 * temporary compliance hold — and withdrawing every seller's offer would destroy
 * their prices and their buy-box seniority to solve a problem that may last a
 * day. Pausing keeps the offer intact and simply unsellable.
 *
 * EACH OFFER GOES THROUGH `PauseOfferAction`, not a mass `update()`. A bulk
 * write would skip the audit entry and the per-offer event, and a seller asking
 * "why did my listing stop selling?" would find nothing in the trail. The cost
 * is one query per offer; a product's offer count is bounded by its seller
 * count, which is small.
 *
 * `byCascade: true` is the flag the re-publish half reads to reactivate exactly
 * these offers and leave alone the ones a seller paused themselves.
 */
final class CascadePauseOffersOnProductArchivedAction extends BaseAction
{
    public function __construct(
        private readonly OfferRepositoryContract $offers,
        private readonly PauseOfferAction $pause,
    ) {}

    /**
     * @return int the number of offers paused
     */
    public function handle(mixed ...$arguments): int
    {
        $productUuid = (string) $arguments[0];
        $paused = 0;

        foreach ($this->offers->activeForProduct($productUuid) as $offer) {
            $this->pause->run($offer, __('offer.cascade.product_archived'), true);
            $paused++;
        }

        return $paused;
    }
}
