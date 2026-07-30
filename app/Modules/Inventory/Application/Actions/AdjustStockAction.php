<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Context\AuditContext;
use App\Modules\Inventory\Application\Actions\Concerns\RecordsMovements;
use App\Modules\Inventory\Domain\Contracts\StockItemRepositoryContract;
use App\Modules\Inventory\Domain\DTOs\AdjustStockDTO;
use App\Modules\Inventory\Domain\Enums\StockMovementType;
use App\Modules\Inventory\Domain\Events\StockAdjusted;
use App\Modules\Inventory\Domain\Events\StockItemCreated;
use App\Modules\Inventory\Domain\Models\StockItem;

/**
 * Bring a pool's on-hand to what the seller says they have (§3.1, ADR-048).
 *
 * THE MIRROR. The seller types a number on the Offer form and Inventory records
 * it here — so the same figure lives in two places, kept in step by an event
 * rather than a shared row. That is the cost ADR-048 accepted, and this action is
 * where it is paid: it is idempotent in the sense that matters, because it
 * receives an ABSOLUTE quantity and computes the delta itself. A replayed or
 * out-of-order event converges on the seller's number instead of compounding.
 *
 * IT CREATES THE POOL IF THERE IS NONE. A pool comes into being because somebody
 * listed something, never on its own, so `OfferCreated` and `OfferStockChanged`
 * both land here and the first one to arrive wins the creation.
 *
 * RESERVED IS UNTOUCHED. A seller correcting their shelf count says nothing about
 * units already promised to a checkout — and if their new on-hand is below what
 * is reserved, the clamp in `RecordsMovements` keeps the projection coherent
 * while `available` correctly reads zero. Order will find fewer units than it
 * held, which is a real business problem the seller caused and not one this
 * action can paper over.
 *
 * @see docs/modules/Inventory.md §3.1
 */
final class AdjustStockAction extends BaseAction
{
    use RecordsMovements;

    private bool $created = false;

    private int $previousOnHand = 0;

    public function __construct(
        private readonly StockItemRepositoryContract $items,
    ) {}

    public function handle(mixed ...$arguments): StockItem
    {
        /** @var AdjustStockDTO $data */
        $data = $arguments[0];

        $onHand = max(0, $data->onHand);

        $item = $this->items->lockForUpdate($data->sellingOrgUuid, $data->variantUuid);

        if ($item === null) {
            $item = $this->createPool($data);
        }

        $this->previousOnHand = $item->on_hand;

        // Keep provenance current: a seller can re-list a variant, and the pool
        // should point at the offer that owns it now.
        if ($data->offerUuid !== null && $item->offer_uuid !== $data->offerUuid) {
            $item->forceFill(['offer_uuid' => $data->offerUuid])->save();
        }

        $delta = $onHand - $item->on_hand;

        if ($delta === 0) {
            // Nothing moved, so nothing is recorded. A movement with a zero
            // delta would be noise in the one place a seller goes to understand
            // their stock, and a replayed event is exactly how it would arrive.
            return $item;
        }

        AuditContext::withReasonFor($data->note, function () use ($item, $delta, $data): void {
            $this->recordMovement(
                $item,
                StockMovementType::SellerAdjustment,
                onHandDelta: $delta,
                note: $data->note,
            );
        });

        return $item;
    }

    protected function after(mixed $result, mixed ...$arguments): void
    {
        /** @var StockItem $result */
        if ($this->created) {
            StockItemCreated::dispatch(
                $result->getKey(),
                $result->uuid,
                $result->variant_uuid,
                $result->product_uuid,
                $result->selling_org_uuid,
                $result->on_hand,
            );
        }

        if ($this->previousOnHand === $result->on_hand) {
            return;
        }

        StockAdjusted::dispatch(
            $result->getKey(),
            $result->uuid,
            $result->variant_uuid,
            $result->selling_org_uuid,
            $this->previousOnHand,
            $result->on_hand,
            $result->available(),
        );
    }

    /**
     * A pool starts EMPTY, and the seller's quantity arrives as the first
     * movement rather than as an initial value.
     *
     * Seeding `on_hand` directly would put units into the projection that the
     * ledger never accounted for — the one thing ADR-050 exists to prevent, and
     * invisible until somebody tried to rebuild.
     */
    private function createPool(AdjustStockDTO $data): StockItem
    {
        $this->created = true;

        return StockItem::query()->create([
            'variant_uuid' => $data->variantUuid,
            'product_uuid' => $data->productUuid,
            'offer_uuid' => $data->offerUuid,
            'selling_org_id' => $data->sellingOrgId,
            'selling_org_uuid' => $data->sellingOrgUuid,
            'on_hand' => 0,
            'reserved' => 0,
        ]);
    }
}
