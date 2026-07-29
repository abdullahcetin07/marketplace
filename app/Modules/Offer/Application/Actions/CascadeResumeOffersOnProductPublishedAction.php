<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;

/**
 * The product came back, so the offers its archiving paused come back with it
 * (§3.5).
 *
 * THE OTHER HALF OF THE CASCADE, and the reason `paused_by_cascade` is a column
 * rather than an inference. Without it, re-publishing would either resume
 * nothing (leaving every seller silently dark until they noticed) or resume
 * everything (re-listing offers sellers had deliberately paused for their own
 * reasons). Only the flag can tell those apart.
 *
 * NOT IN THE WORK ORDER'S ACTION LIST — §12 names only the pause half — but
 * §3.5 states the behaviour ("On re-publish, paused-by-cascade offers are
 * reactivated"), so the action exists to satisfy the spec rather than the
 * summary of it.
 *
 * A product published for the FIRST time has no cascade-paused offers, so this
 * is a no-op on the common path — which is why it is safe to hang off
 * `ProductPublished` generally rather than needing a distinct "re-published"
 * event the Catalog does not emit.
 */
final class CascadeResumeOffersOnProductPublishedAction extends BaseAction
{
    public function __construct(
        private readonly OfferRepositoryContract $offers,
        private readonly ResumeOfferAction $resume,
    ) {}

    /**
     * @return int the number of offers resumed
     */
    public function handle(mixed ...$arguments): int
    {
        $productUuid = (string) $arguments[0];
        $resumed = 0;

        foreach ($this->offers->cascadePausedForProduct($productUuid) as $offer) {
            $this->resume->run($offer, __('offer.cascade.product_republished'), true);
            $resumed++;
        }

        return $resumed;
    }
}
