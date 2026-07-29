<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Listeners;

use App\Modules\Offer\Application\Actions\CascadePauseOffersOnProductArchivedAction;

/**
 * Offer's ear on the catalog's product lifecycle (§3.5).
 *
 * THE EVENT IS NOT TYPE-HINTED, AND THAT IS THE POINT. Offer imports no module
 * — Catalog included, with no events escape hatch (`LayeringTest`). So the
 * subscription is registered by CLASS-STRING in the service provider and the
 * payload arrives here as a plain object whose `productUuid` this reads. The
 * dependency is on a NAME and a property, not on a class this module compiles
 * against.
 *
 * The cost is real and worth stating: a rename of Catalog's event class, or of
 * its `productUuid` property, breaks this silently at runtime rather than at
 * build time. That is the price of the strictest boundary on the platform, and
 * it is bounded by a feature test that fires the real event and asserts the
 * offers paused.
 *
 * @see App\Modules\Offer\Application\Actions\CascadePauseOffersOnProductArchivedAction
 */
final class PauseOffersOnProductArchived
{
    public function __construct(
        private readonly CascadePauseOffersOnProductArchivedAction $cascade,
    ) {}

    public function handle(object $event): void
    {
        $productUuid = $event->productUuid ?? null;

        if (! is_string($productUuid) || $productUuid === '') {
            return;
        }

        $this->cascade->run($productUuid);
    }
}
