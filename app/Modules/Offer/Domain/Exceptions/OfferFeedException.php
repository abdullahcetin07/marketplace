<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One ITEM of a seller's offer feed could not be applied (ADR-076).
 *
 * **AN ITEM FAILURE IS NOT A BATCH FAILURE**, and that is the whole reason this
 * class is separate from `OfferException`. A seller pushing four thousand SKUs
 * every morning will have barcodes the catalogue does not carry; the outcome
 * nobody wants is the other 3,999 rolled back because of them. Each item is its
 * own action invocation, the adapter catches this, records it, and moves on.
 *
 * **EVERY FAILURE CARRIES A MACHINE REASON**, because the caller is a machine.
 * The API renders `reason: product_not_in_catalog` beside the item; the CSV import
 * puts the Turkish sentence in the downloadable report. Same exception, two
 * audiences — which is why both live on it.
 *
 * NOT REPORTABLE: a feed with a stale barcode is an ordinary Tuesday, not an
 * incident.
 *
 * @see App\Modules\Offer\Application\Actions\SyncSellerOfferAction
 */
final class OfferFeedException extends BaseException
{
    /**
     * The GTIN matches no PUBLISHED product.
     *
     * **ONE REASON FOR "UNKNOWN" AND "NOT PUBLISHED".** Telling them apart would
     * let a seller enumerate the unpublished catalogue one barcode at a time, and
     * the seller's next action is the same either way: ask the platform to add the
     * product.
     */
    public static function productNotInCatalog(string $gtin): self
    {
        return self::make("Bu barkod yayındaki katalogda yok: {$gtin}")
            ->withContext(['reason' => 'product_not_in_catalog', 'gtin' => $gtin])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A stock-only or withdraw item for a variant this seller has no offer on.
     *
     * **STOCK CANNOT CREATE AN OFFER** — there is no price to create it with — so
     * the honest answer is "run sync first" rather than inventing a price or
     * silently doing nothing.
     */
    public static function offerNotFound(string $gtin): self
    {
        return self::make("Bu barkod için teklifiniz yok; önce fiyatla birlikte gönderin: {$gtin}")
            ->withContext(['reason' => 'offer_not_found', 'gtin' => $gtin])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function invalidPrice(string $gtin): self
    {
        return self::make("Geçersiz fiyat: {$gtin}")
            ->withContext(['reason' => 'invalid_price', 'gtin' => $gtin])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function invalidStock(string $gtin): self
    {
        return self::make("Geçersiz stok: {$gtin}")
            ->withContext(['reason' => 'invalid_stock', 'gtin' => $gtin])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * A struck-through price below the selling price, which reads as a discount
     * that is not one.
     */
    public static function listPriceBelowPrice(string $gtin): self
    {
        return self::make("Piyasa fiyatı satış fiyatından düşük olamaz: {$gtin}")
            ->withContext(['reason' => 'list_price_below_price', 'gtin' => $gtin])
            ->withStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The machine-readable half, for the API's per-item report.
     */
    public function reason(): string
    {
        $reason = $this->getContext()['reason'] ?? 'failed';

        return is_string($reason) ? $reason : 'failed';
    }
}
