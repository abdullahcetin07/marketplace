<?php

declare(strict_types=1);

namespace App\Modules\Offer\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Offer\Domain\DTOs\UpdateOfferStockDTO;
use App\Modules\Offer\Domain\Enums\OfferStatus;
use App\Modules\Offer\Domain\Events\OfferStockChanged;
use App\Modules\Offer\Domain\Exceptions\OfferException;
use App\Modules\Offer\Domain\Models\Offer;

/**
 * A restock, or a seller marking something sold out.
 *
 * SETTING ZERO IS NOT A STATUS CHANGE. The offer stays `Active` and simply stops
 * winning the buy box, because out-of-stock is derived (ADR-043). That is what
 * lets a seller restock tomorrow and be live again without touching status, and
 * it is why this action never writes to `status`.
 *
 * ABSOLUTE, NOT A DELTA — the seller says "elimde 12 adet var". A delta needs a
 * known starting point and this sprint has no reservations to guarantee one; when
 * Inventory ships and becomes the stock authority, this is the action that gets
 * migrated (ADR-043).
 *
 * Suspended and withdrawn offers refuse the write for the same reason they refuse
 * a re-price: neither is a state the seller may edit out of.
 *
 * @see docs/modules/Offer.md §3.3
 */
final class UpdateOfferStockAction extends BaseAction
{
    private int $previousStock;

    public function handle(mixed ...$arguments): Offer
    {
        /** @var Offer $offer */
        $offer = $arguments[0];
        /** @var UpdateOfferStockDTO $data */
        $data = $arguments[1];

        if ($offer->status === OfferStatus::Suspended || $offer->status === OfferStatus::Withdrawn) {
            throw OfferException::invalidTransition($offer->status, $offer->status);
        }

        $this->previousStock = $offer->stock_quantity;

        AuditContext::withReasonFor($data->reason, function () use ($offer, $data): void {
            // Clamped rather than rejected: a negative quantity is a client bug,
            // and "you have none left" is the honest reading of it. The column
            // is unsigned, so this is also what keeps the write from throwing a
            // constraint violation at a seller.
            $offer->forceFill(['stock_quantity' => max(0, $data->stockQuantity)])->save();
        });

        return $offer;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var Offer $result */
        OfferStockChanged::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->product_uuid,
            $result->selling_org_id,
            $result->selling_org_uuid,
            $this->previousStock,
            $result->stock_quantity,
        );
    }
}
