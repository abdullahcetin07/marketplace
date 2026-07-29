<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Offer\Domain\DTOs\UpdateOfferPriceDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferPriceChanged;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * A re-price — the most frequent write this module takes.
 *
 * FORENSICALLY RECORDED. The write happens inside `AuditContext::withReasonFor()`
 * so the Auditable trait captures the before/after and the seller's reason in one
 * immutable entry (ADR-027). A price is exactly the fact a dispute turns on:
 * what was it when the buyer looked, and who changed it.
 *
 * A SUSPENDED OFFER CANNOT BE RE-PRICED. Editing your way out of an admin's
 * oversight action — change the abusive price, quietly keep selling — is the
 * obvious abuse, so the refusal is on the write, not only on the status
 * transitions.
 *
 * PATCH SEMANTICS ON THE LIST PRICE. Clearing the struck-through price when a
 * campaign ends is a real edit and must not be indistinguishable from leaving it
 * alone, which is what `present` distinguishes.
 *
 * @see docs/modules/Offer.md §3.1
 */
final class UpdateOfferPriceAction extends BaseAction
{
    private int $previousPrice;

    private ?int $previousListPrice;

    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        /** @var UpdateOfferPriceDTO $data */
        $data = $arguments[1];

        if ($offer->status === OfferStatus::Suspended || $offer->status === OfferStatus::Withdrawn) {
            throw OfferException::invalidTransition($offer->status, $offer->status);
        }

        $listPrice = $data->has('list_price_minor') ? $data->listPriceMinor : $offer->list_price_minor;

        if ($data->priceMinor <= 0) {
            throw OfferException::invalidPrice();
        }

        if ($listPrice !== null && $listPrice < $data->priceMinor) {
            throw OfferException::listPriceBelowPrice();
        }

        $this->previousPrice = $offer->price_minor;
        $this->previousListPrice = $offer->list_price_minor;

        AuditContext::withReasonFor($data->reason, function () use ($offer, $data, $listPrice): void {
            $offer->forceFill([
                'price_minor' => $data->priceMinor,
                'list_price_minor' => $listPrice,
            ])->save();
        });

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferPriceChanged::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $this->previousPrice,
            $result->price_minor,
            $this->previousListPrice,
            $result->list_price_minor,
        );
    }
}
