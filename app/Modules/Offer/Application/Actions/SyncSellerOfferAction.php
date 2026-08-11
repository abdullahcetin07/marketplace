<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferFeedOutcome;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * One feed item → an offer created, updated, or left exactly as it was (ADR-076).
 *
 * **THE BRAIN BEHIND BOTH DOORS.** The REST API and the seller CSV import are
 * adapters: they translate a JSON item or a spreadsheet row into a
 * `SyncOfferDTO`, call this once per item, and render what comes back. Neither
 * holds feed logic, because two copies of "what does this item mean" is how the
 * two doors start disagreeing.
 *
 * **IT DRIVES THE EXISTING OFFER ACTIONS AND WRITES NO `Offer` MODEL.** This is
 * the load-bearing rule, the same one ADR-074 states for the catalogue import:
 * `CreateOfferAction` and `UpdateOfferStockAction` emit `OfferCreated` and
 * `OfferStockChanged`, which **Inventory mirrors on-hand from** (ADR-048) and the
 * search index consumes. A model write here would produce an offer that is correct
 * in the table and invisible to availability and to search — right in one place,
 * wrong everywhere a buyer looks.
 *
 * **ONE ITEM PER INVOCATION, AND THAT IS WHY THE BATCH LOOP IS IN THE ADAPTER.**
 * `BaseAction` wraps `handle()` in a transaction; looping inside it would put four
 * thousand items in one transaction, where a single bad barcode rolls back a
 * morning's work. Each item gets its own.
 *
 * **UNCHANGED IS A REAL OUTCOME.** A seller pushes their whole catalogue daily and
 * most of it did not move; skipping the untouched fields means no event, so
 * Inventory and search are not woken four thousand times to be told nothing.
 *
 * **THE SELLER COMES FROM THE DTO, WHICH THE ADAPTER FILLED FROM THE AUTHENTICATED
 * ACTOR** — never from the item. A token cannot name another merchant's org.
 *
 * @see docs/modules/Offer.md — the seller offer feed
 */
final class SyncSellerOfferAction extends BaseFeedAction
{
    public function __construct(
        private readonly CatalogQueryContract $catalog,
        private readonly OfferRepositoryContract $offers,
        private readonly CreateOfferAction $create,
        private readonly UpdateOfferPriceAction $updatePrice,
        private readonly UpdateOfferStockAction $updateStock,
    ) {}

    public function handle(mixed ...$arguments): OfferFeedOutcome
    {
        /** @var SyncOfferDTO $data */
        $data = $arguments[0];

        $this->assertPricing($data);

        $variantUuid = $this->resolveVariant($this->catalog, $data->gtin);
        $existing = $this->offers->duplicateFor($data->sellingOrgId, $variantUuid);

        if ($existing === null) {
            return $this->createOffer($data, $variantUuid);
        }

        return $this->updateOffer($existing, $data);
    }

    /**
     * A brand-new offer needs both halves; the feed cannot invent either.
     */
    private function createOffer(SyncOfferDTO $data, string $variantUuid): OfferFeedOutcome
    {
        if ($data->priceMinor === null) {
            throw OfferFeedException::invalidPrice($data->gtin);
        }

        if ($data->stockQuantity === null) {
            throw OfferFeedException::invalidStock($data->gtin);
        }

        $this->create->run(new CreateOfferDTO(
            variantUuid: $variantUuid,
            sellingOrgId: $data->sellingOrgId,
            sellingOrgUuid: $data->sellingOrgUuid,
            storeUuid: $data->storeUuid,
            priceMinor: $data->priceMinor,
            stockQuantity: $data->stockQuantity,
            listPriceMinor: $data->listPriceMinor,
        ));

        return OfferFeedOutcome::Created;
    }

    /**
     * Two independent decisions, because they are two different events.
     *
     * **PRICE AND STOCK MOVE SEPARATELY**, so a feed that changed only the stock
     * emits only `OfferStockChanged` — the event Inventory mirrors — and does not
     * write an audit entry claiming somebody re-priced. A single "update the
     * offer" call would have collapsed both into one meaningless change record.
     */
    private function updateOffer(Offer $offer, SyncOfferDTO $data): OfferFeedOutcome
    {
        $touched = false;

        if ($this->pricingChanged($offer, $data)) {
            $this->updatePrice->run($offer, new UpdateOfferPriceDTO(
                priceMinor: $data->priceMinor ?? $offer->price_minor,
                listPriceMinor: $data->listPriceMinor,
                /*
                | `present` IS WHAT SEPARATES "not sent" FROM "cleared". A feed item
                | with no `list_price` leaves the struck-through price alone; only
                | an item that carried the field may change it.
                */
                present: $data->listPriceMinor === null ? ['priceMinor'] : ['priceMinor', 'listPriceMinor'],
                reason: self::REASON,
            ));

            $touched = true;
        }

        if ($data->stockQuantity !== null && $data->stockQuantity !== $offer->stock_quantity) {
            $this->updateStock->run($offer, new UpdateOfferStockDTO(
                stockQuantity: $data->stockQuantity,
                reason: self::REASON,
            ));

            $touched = true;
        }

        return $touched ? OfferFeedOutcome::Updated : OfferFeedOutcome::Unchanged;
    }

    /**
     * Whether the money actually moved.
     *
     * A LIST PRICE THAT WAS NOT SENT IS NOT A CHANGE. Comparing `null` against the
     * stored value would re-price every offer on every feed run, which is how an
     * audit trail fills with edits nobody made.
     */
    private function pricingChanged(Offer $offer, SyncOfferDTO $data): bool
    {
        if ($data->priceMinor !== null && $data->priceMinor !== $offer->price_minor) {
            return true;
        }

        return $data->listPriceMinor !== null && $data->listPriceMinor !== $offer->list_price_minor;
    }

    /**
     * The pricing rules the feed can answer before touching anything.
     *
     * CHECKED HERE AS WELL AS IN `CreateOfferAction`, and not as a duplicate: the
     * action throws `OfferException`, which an adapter would report as an
     * unhelpful generic failure. The feed's contract is a machine reason per item,
     * so the feed's own refusals carry one.
     */
    private function assertPricing(SyncOfferDTO $data): void
    {
        if ($data->priceMinor !== null && $data->priceMinor <= 0) {
            throw OfferFeedException::invalidPrice($data->gtin);
        }

        if ($data->stockQuantity !== null && $data->stockQuantity < 0) {
            throw OfferFeedException::invalidStock($data->gtin);
        }

        if ($data->listPriceMinor !== null
            && $data->priceMinor !== null
            && $data->listPriceMinor < $data->priceMinor) {
            throw OfferFeedException::listPriceBelowPrice($data->gtin);
        }
    }
}
