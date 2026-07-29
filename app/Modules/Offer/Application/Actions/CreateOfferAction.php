<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\CatalogQueryContract;
use App\Core\Domain\Contracts\StoreQueryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Offer\Domain\Contracts\OfferRepositoryContract;
use App\Modules\Offer\Domain\DTOs\CreateOfferDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferCreated;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * A seller lists a catalog variant at a price — the module's defining write.
 *
 * IT GOES LIVE IMMEDIATELY (ADR-044). There is no draft, no review queue and no
 * moderator: the product was already moderated, and price and stock are the
 * seller's commercial freedom. What runs before the insert is VALIDATION, which
 * is a different thing from moderation — every check below refuses a listing
 * that would be incoherent, not one somebody dislikes.
 *
 * THE FOUR PRECONDITIONS (§3.4), each asked of whoever owns the answer:
 *
 *   1. The variant exists            → CatalogQueryContract
 *   2. Its product is Published      → CatalogQueryContract
 *   3. The store is live and theirs  → StoreQueryContract
 *   4. No live offer already exists  → this module's own repository
 *
 * Not one of them is answered by importing another module (ADR-046). The org
 * scope itself — may this actor sell on behalf of this company — is the
 * POLICY's question, checked before the action is ever reached; an action
 * validates the data, a policy validates the actor.
 *
 * `product_uuid` IS RESOLVED HERE, not accepted from the caller. Letting a
 * payload carry both would let it carry a product the variant does not belong
 * to, and every buy-box read after that would group the offer under the wrong
 * page.
 *
 * @see docs/modules/Offer.md §3.4
 */
final class CreateOfferAction extends BaseAction
{
    public function __construct(
        private readonly CatalogQueryContract $catalog,
        private readonly StoreQueryContract $stores,
        private readonly OfferRepositoryContract $offers,
        private readonly CurrencyRepositoryContract $currencies,
    ) {}

    public function handle(mixed ...$arguments): Offer
    {
        /** @var CreateOfferDTO $data */
        $data = $arguments[0];

        $productUuid = $this->resolvePublishedProduct($data->variantUuid);

        $this->assertStoreUsable($data->storeUuid, $data->sellingOrgId);
        $this->assertPricing($data->priceMinor, $data->listPriceMinor);

        if ($this->offers->duplicateFor($data->sellingOrgId, $data->variantUuid) !== null) {
            throw OfferException::duplicateForVariant($data->variantUuid);
        }

        return Offer::query()->create([
            'variant_uuid' => $data->variantUuid,
            'product_uuid' => $productUuid,
            'selling_org_id' => $data->sellingOrgId,
            'selling_org_uuid' => $data->sellingOrgUuid,
            'store_uuid' => $data->storeUuid,
            'price_minor' => $data->priceMinor,
            'list_price_minor' => $data->listPriceMinor,
            // Single-currency in practice this sprint (§13.1) but stored per
            // offer, so multi-currency later is data, not a migration.
            'currency_id' => $data->currencyId ?? $this->currencies->default()->getKey(),
            'stock_quantity' => max(0, $data->stockQuantity),
            'status' => OfferStatus::Active,
        ]);
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferCreated::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $result->selling_org_uuid,
            $result->store_uuid,
            $result->price_minor,
            $result->stock_quantity,
        );
    }

    /**
     * Preconditions 1 and 2, and the parent uuid the row denormalizes.
     */
    private function resolvePublishedProduct(string $variantUuid): string
    {
        $productUuid = $this->catalog->productUuidForVariant($variantUuid);

        if ($productUuid === null) {
            throw OfferException::variantNotFound($variantUuid);
        }

        if (! $this->catalog->isProductPublished($productUuid)) {
            throw OfferException::productNotPublished($productUuid);
        }

        return $productUuid;
    }

    /**
     * Precondition 3. Both halves matter: a live store belonging to someone
     * else would let a seller list under a competitor's storefront, and the
     * company's own paused store is not somewhere a buyer can reach.
     */
    private function assertStoreUsable(string $storeUuid, int $sellingOrgId): void
    {
        if ($this->stores->liveStoreUuidsForOrganization($sellingOrgId) === []) {
            throw OfferException::noActiveStore();
        }

        if (! $this->stores->isLive($storeUuid)
            || $this->stores->organizationIdFor($storeUuid) !== $sellingOrgId) {
            throw OfferException::storeNotUsable($storeUuid);
        }
    }

    /**
     * The money rules (§3.4). Shared with the price update, which enforces the
     * same two facts about the same two columns.
     */
    private function assertPricing(int $priceMinor, ?int $listPriceMinor): void
    {
        if ($priceMinor <= 0) {
            throw OfferException::invalidPrice();
        }

        if ($listPriceMinor !== null && $listPriceMinor < $priceMinor) {
            throw OfferException::listPriceBelowPrice();
        }
    }
}
