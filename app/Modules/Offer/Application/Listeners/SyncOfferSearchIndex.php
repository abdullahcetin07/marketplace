<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Listeners;

use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\Events\OfferCreated;
use App\Modules\Offer\Domain\Events\OfferPaused;
use App\Modules\Offer\Domain\Events\OfferPriceChanged;
use App\Modules\Offer\Domain\Events\OfferReinstated;
use App\Modules\Offer\Domain\Events\OfferResumed;
use App\Modules\Offer\Domain\Events\OfferStockChanged;
use App\Modules\Offer\Domain\Events\OfferSuspended;
use App\Modules\Offer\Domain\Events\OfferWithdrawn;
use App\Modules\Offer\Domain\Models\Offer;
use Illuminate\Events\Dispatcher;

/**
 * Keeps the offer index in step with everything that changes buyability (§10).
 *
 * DRIVEN BY EVENTS, NOT BY SCOUT'S OBSERVER. Scout syncs on every save, which
 * here would mean re-indexing on an admin's `suspension_reason` write and on
 * every no-op the panel produces. The events fire after commit
 * (`BaseAction::after()`), so nothing is ever indexed from a transaction that
 * rolled back.
 *
 * `searchable()` IS IDEMPOTENT AND SELF-DECIDING: the model's
 * `shouldBeSearchable()` decides index-or-drop, so one call handles a price
 * change (still buyable), a stock change to zero (no longer buyable) and a
 * pause (likewise). That is why nearly every event routes to the same method —
 * the rule lives in one place instead of being restated per event.
 *
 * WITHDRAWAL IS THE EXCEPTION. The row is soft-deleted, so it can no longer be
 * loaded to be asked; the document is removed by key.
 *
 * A FAILED INDEX MUST NOT UNDO A SALE. Indexing is queued onto the `search`
 * queue, so a cluster that is down delays discoverability rather than failing a
 * seller's price change.
 *
 * @see App\Modules\Offer\Domain\Models\Offer::toSearchableArray()
 */
final class SyncOfferSearchIndex
{
    public function __construct(
        private readonly OfferRepositoryContract $offers,
        private readonly StoreQueryContract $stores,
    ) {}

    /**
     * Every event that can change whether an offer is buyable right now.
     */
    public function onOfferChanged(object $event): void
    {
        $uuid = $event->offerUuid ?? null;

        if (is_string($uuid) && $uuid !== '') {
            $this->offers->findByUuid($uuid)?->searchable();
        }
    }

    public function onWithdrawn(OfferWithdrawn $event): void
    {
        // Soft-deleted, so the default scope will not find it — the document is
        // removed by key from a model that only needs to carry that key.
        $offer = Offer::withTrashed()->where('uuid', $event->offerUuid)->first();

        $offer?->unsearchable();
    }

    /**
     * A STOREFRONT WENT DARK OR CAME BACK.
     *
     * The third buy-box condition lives in Store, and the model deliberately
     * does not ask for it per record (see `Offer::shouldBeSearchable()`), so it
     * reaches the index here: when a store's state changes, its offers are
     * re-evaluated in one pass rather than one query per offer forever.
     *
     * The event is UNTYPED because Offer imports no module — subscribed by
     * class-string, exactly like the Catalog product cascade. @see
     * OfferServiceProvider::subscribeToStoreLifecycle()
     */
    public function onStoreLifecycleChanged(object $event): void
    {
        $storeUuid = $event->storeUuid ?? null;

        if (! is_string($storeUuid) || $storeUuid === '') {
            return;
        }

        $live = $this->stores->isLive($storeUuid);

        foreach ($this->offers->forStore($storeUuid) as $offer) {
            // Live: let the model decide (a paused or sold-out offer still
            // stays out). Dark: nothing from this shop belongs in the index.
            $live ? $offer->searchable() : $offer->unsearchable();
        }
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OfferCreated::class => 'onOfferChanged',
            OfferPriceChanged::class => 'onOfferChanged',
            OfferStockChanged::class => 'onOfferChanged',
            OfferPaused::class => 'onOfferChanged',
            OfferResumed::class => 'onOfferChanged',
            OfferSuspended::class => 'onOfferChanged',
            OfferReinstated::class => 'onOfferChanged',
            OfferWithdrawn::class => 'onWithdrawn',
        ];
    }
}
