<?php

declare(strict_types=1);

namespace App\Modules\Offer\Presentation\Controllers\Api\Seller;

use App\Core\Application\Actions\BaseAction;
use App\Core\Presentation\Controllers\BaseController;
use App\Models\User;
use App\Modules\Offer\Application\Actions\SyncSellerOfferAction;
use App\Modules\Offer\Application\Actions\SyncSellerStockAction;
use App\Modules\Offer\Application\Actions\WithdrawSellerOfferAction;
use App\Modules\Offer\Application\Import\SellerFeedIdentity;
use App\Modules\Offer\Domain\DTOs\SyncOfferDTO;
use App\Modules\Offer\Domain\Enums\OfferFeedOutcome;
use App\Modules\Offer\Domain\Exceptions\OfferFeedException;
use App\Modules\Offer\Presentation\Requests\SellerOfferFeedRequest;
use App\Modules\Offer\Presentation\Requests\SellerStockFeedRequest;
use App\Modules\Offer\Presentation\Requests\SellerWithdrawFeedRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The seller's price/stock feed over HTTP (ADR-076, door one).
 *
 * **A BATCH IS A REPORT, NOT A TRANSACTION.** Every call answers `200` with a
 * per-item result even when some items failed, because a seller pushing four
 * thousand SKUs needs to know WHICH forty were rejected — an all-or-nothing 422
 * would throw away 3,960 good updates over a handful of stale barcodes. `422` is
 * reserved for a request that is malformed as a whole: bad shape, or over the
 * batch ceiling.
 *
 * **THE MERCHANT IS RESOLVED FROM THE TOKEN, ONCE PER CALL**, never read from the
 * payload — there is no field for it (@see `SellerFeedIdentity`). Resolving it once
 * rather than per item also means a seller whose store went dark mid-batch gets one
 * clear refusal instead of four thousand identical ones.
 *
 * **THE CONTROLLER HOLDS THE LOOP AND NOTHING ELSE.** Each item is one action
 * invocation, so one item's failure rolls back only itself; the meaning of an item
 * lives in the action, which the CSV import calls the same way.
 *
 * @see docs/modules/Offer.md — the seller offer feed
 * @see docs/superpowers/specs/2026-08-11-seller-offer-feed-design.md
 */
final class OfferFeedController extends BaseController
{
    public function __construct(
        private readonly SellerFeedIdentity $identity,
        private readonly SyncSellerOfferAction $sync,
        private readonly SyncSellerStockAction $stock,
        private readonly WithdrawSellerOfferAction $withdraw,
    ) {}

    /**
     * POST /api/v1/seller/offers/sync — price + stock upsert.
     */
    public function sync(SellerOfferFeedRequest $request): JsonResponse
    {
        return $this->ok($this->run($this->sync, $request->items()));
    }

    /**
     * POST /api/v1/seller/offers/stock — the hourly fast path.
     */
    public function stock(SellerStockFeedRequest $request): JsonResponse
    {
        return $this->ok($this->run($this->stock, $request->items()));
    }

    /**
     * POST /api/v1/seller/offers/withdraw — take offers off sale.
     */
    public function withdraw(SellerWithdrawFeedRequest $request): JsonResponse
    {
        return $this->ok($this->run($this->withdraw, $request->items()));
    }

    /**
     * The loop, and the tally the caller is actually here for.
     *
     * **A FAILURE IS CAUGHT PER ITEM AND CARRIES A MACHINE REASON.** The seller's
     * system branches on `product_not_in_catalog` versus `offer_not_found` — the
     * first means "ask the platform to add this product", the second "send a price
     * first" — so a human sentence alone would be unusable.
     *
     * @param array<int, array{gtin: string, price?: ?int, stock?: ?int, list_price?: ?int}> $items
     *
     * @return array<string, mixed>
     */
    private function run(BaseAction $action, array $items): array
    {
        $seller = $this->identity->forUser((int) $this->actor()->getKey());

        $results = [];
        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $gtin = (string) $item['gtin'];

            try {
                /** @var OfferFeedOutcome $outcome */
                $outcome = $action->run(new SyncOfferDTO(
                    sellingOrgId: $seller['orgId'],
                    sellingOrgUuid: $seller['orgUuid'],
                    storeUuid: $seller['storeUuid'],
                    gtin: $gtin,
                    priceMinor: $item['price'] ?? null,
                    stockQuantity: $item['stock'] ?? null,
                    listPriceMinor: $item['list_price'] ?? null,
                ));

                $tally[$outcome->value]++;
                $results[] = ['gtin' => $gtin, 'status' => $outcome->value];
            } catch (OfferFeedException $exception) {
                $tally['failed']++;
                $results[] = ['gtin' => $gtin, 'status' => 'failed', 'reason' => $exception->reason()];
            }
        }

        return [
            'processed' => count($items),
            ...$tally,
            'items' => $results,
        ];
    }

    /**
     * The authenticated seller. A guard that resolved nobody is a 404 rather than
     * a 500 — the same answer every other public surface here gives.
     */
    private function actor(): User
    {
        $actor = current_actor();

        if (! $actor instanceof User) {
            throw new NotFoundHttpException;
        }

        return $actor;
    }
}
