<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Listeners;

use App\Modules\Offer\Application\Actions\CascadeResumeOffersOnProductPublishedAction;

/**
 * The re-publish half of the cascade (§3.5).
 *
 * Untyped payload for the same reason as its sibling — see
 * `PauseOffersOnProductArchived` for why Offer subscribes by class-string.
 *
 * Fires on EVERY publication, including a product's first, where it finds no
 * cascade-paused offers and does nothing. Cheaper and less brittle than asking
 * Catalog to distinguish "published" from "re-published", which is a state it
 * does not track and should not have to.
 *
 * @see App\Modules\Offer\Application\Actions\CascadeResumeOffersOnProductPublishedAction
 */
final class ResumeOffersOnProductPublished
{
    public function __construct(
        private readonly CascadeResumeOffersOnProductPublishedAction $cascade,
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
