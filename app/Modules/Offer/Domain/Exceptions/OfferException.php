<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Exceptions;

use App\Core\Domain\Exceptions\BaseException;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use Illuminate\Http\Response;

/**
 * An offer could not be created or moved.
 *
 * EXPECTED DOMAIN REFUSALS, NOT INCIDENTS — listing an unpublished product,
 * pricing below zero, pausing a withdrawn offer. `$reportable` stays false
 * (BaseException's default): a seller making a mistake is not a page for
 * somebody at 3am. Never a 500.
 *
 * Each carries a machine-readable `reason` in its context, so a panel can react
 * to the specific refusal rather than parsing a sentence.
 *
 * @see docs/modules/Offer.md §3.4
 */
final class OfferException extends BaseException
{
    protected int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * §3.2 — the seller already has a live offer for this variant. They edit
     * that one; they do not hold two competing offers for the same SKU.
     */
    public static function duplicateForVariant(string $variantUuid): self
    {
        return self::make('You already have an offer for this variant. Edit it instead.')
            ->withContext(['reason' => 'duplicate_offer', 'variant_uuid' => $variantUuid]);
    }

    /**
     * The variant is not in the catalog at all. Distinct from "not published"
     * on purpose: one is a bad reference, the other is a moderation state, and
     * conflating them would tell a seller to wait for approval of something
     * that does not exist.
     */
    public static function variantNotFound(string $variantUuid): self
    {
        return self::make('That product variant does not exist.')
            ->withContext(['reason' => 'variant_not_found', 'variant_uuid' => $variantUuid]);
    }

    /**
     * §3.4 — the product is a draft, awaiting review, rejected or archived. An
     * offer would make a moderation state sellable through the side door.
     */
    public static function productNotPublished(string $productUuid): self
    {
        return self::make('That product is not published, so it cannot be offered yet.')
            ->withContext(['reason' => 'product_not_published', 'product_uuid' => $productUuid]);
    }

    /**
     * §3.4 — no store, no offer. A listing has to be attributable to a
     * storefront a buyer can actually reach.
     */
    public static function noActiveStore(): self
    {
        return self::make('This company has no active store, so it cannot list offers.')
            ->withContext(['reason' => 'no_active_store']);
    }

    /**
     * The named store is not this organization's, or is not live. Caught here
     * rather than trusted from the payload, because the store uuid arrives from
     * a form.
     */
    public static function storeNotUsable(string $storeUuid): self
    {
        return self::make('That store cannot carry this offer.')
            ->withContext(['reason' => 'store_not_usable', 'store_uuid' => $storeUuid]);
    }

    /**
     * §3.4 — money is a positive integer of minor units. Zero is not a price;
     * "free" is a campaign concept this module does not have.
     */
    public static function invalidPrice(): self
    {
        return self::make('A price must be greater than zero.')
            ->withContext(['reason' => 'invalid_price']);
    }

    /**
     * §3.4 — a struck-through list price below the actual price advertises a
     * markup as a discount.
     */
    public static function listPriceBelowPrice(): self
    {
        return self::make('The list price cannot be lower than the selling price.')
            ->withContext(['reason' => 'list_price_below_price']);
    }

    /**
     * A lifecycle move the offer's current state does not allow (§3.1) —
     * including every attempt by a seller to touch a suspended offer.
     */
    public static function invalidTransition(OfferStatus $from, OfferStatus $to): self
    {
        return self::make('This offer cannot change state that way.')
            ->withContext([
                'reason' => 'invalid_transition',
                'from' => $from->value,
                'to' => $to->value,
            ]);
    }

    /**
     * Reinstating something that was never suspended. A no-op would silently
     * overwrite the seller's own status with Active.
     */
    public static function notSuspended(): self
    {
        return self::make('This offer is not suspended.')
            ->withContext(['reason' => 'not_suspended']);
    }
}
