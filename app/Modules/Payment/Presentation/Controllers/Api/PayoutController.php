<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Payment\Application\Actions\CreatePayoutAction;
use App\Modules\Payment\Application\Actions\SettlePayoutAction;
use App\Modules\Payment\Domain\Models\Payout;
use App\Modules\Payment\Domain\Support\SellerBalance;
use App\Modules\Payment\Presentation\Requests\CreatePayoutRequest;
use App\Modules\Payment\Presentation\Requests\SettlePayoutRequest;
use App\Modules\Payment\Presentation\Resources\PayoutResource;
use App\Shared\Support\PublicKey;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The admin's payout surface (ADR-062, Payment.md §8).
 *
 * **NOTHING HERE MOVES MONEY.** `store` records that an admin decided to send a
 * seller their balance and debits it so the same lira cannot be promised twice;
 * `settle` records what the bank did afterwards. A human makes the transfer.
 *
 * ADMIN-ONLY THROUGHOUT — there is no seller-facing payout endpoint in v1. A
 * merchant sees their balance; the platform's transfer records are its own.
 *
 * `settle` IS ONE ENDPOINT FOR BOTH OUTCOMES rather than `/pay` and `/fail`,
 * because they are the same decision — "what did the bank say" — and splitting
 * them would let a client mark something paid without ever having considered that
 * it might not have been.
 *
 * @see docs/modules/Payment.md §8
 */
final class PayoutController extends BaseController
{
    public function __construct(
        private readonly CreatePayoutAction $create,
        private readonly SettlePayoutAction $settle,
    ) {}

    /**
     * GET /api/v1/admin/payouts?seller={uuid}
     */
    public function index(CreatePayoutRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Payout::class);

        $seller = $request->query('seller');

        $payouts = Payout::query()
            ->with('currency')
            // BY SHAPE (ADR-059). `seller_org_uuid` is a native uuid column on
            // PostgreSQL, so a filter that is not uuid-shaped would be
            // SQLSTATE[22P02] rather than a miss — the trap, sixth watch.
            ->when(
                is_string($seller) && PublicKey::looksLikeUuid($seller),
                fn ($query) => $query->forSeller((string) $seller),
            )
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->ok(PayoutResource::collection($payouts));
    }

    /**
     * POST /api/v1/admin/payouts
     */
    public function store(CreatePayoutRequest $request): JsonResponse
    {
        $this->authorize('create', Payout::class);

        $actor = current_actor();

        $payout = $this->create->run(
            $request->sellerOrgUuid(),
            $request->amountMinor(),
            (int) $actor?->getKey(),
            $request->note(),
        );

        return $this->created(new PayoutResource($payout->load('currency')));
    }

    /**
     * POST /api/v1/admin/payouts/{payout}/settle
     */
    public function settle(SettlePayoutRequest $request, string $payout): JsonResponse
    {
        if (! PublicKey::looksLikeUuid($payout)) {
            throw new NotFoundHttpException;
        }

        $model = Payout::query()->where('uuid', $payout)->first();

        if ($model === null) {
            throw new NotFoundHttpException;
        }

        $this->authorize('update', $model);

        $actor = current_actor();

        $settled = $this->settle->run(
            $model,
            $request->outcome(),
            (int) $actor?->getKey(),
            $request->detail(),
        );

        return $this->ok(new PayoutResource($settled->load('currency')));
    }

    /**
     * GET /api/v1/admin/sellers/{seller}/balance
     *
     * The numbers a payout is checked against — exposed so an admin can see what
     * they may send before trying to send it, and why the rest is not available
     * yet.
     */
    public function balance(string $seller): JsonResponse
    {
        $this->authorize('viewAny', Payout::class);

        if (! PublicKey::looksLikeUuid($seller)) {
            throw new NotFoundHttpException;
        }

        $balance = SellerBalance::for($seller);

        return $this->ok([
            'seller_id' => $seller,
            // A SUM, computed on read — there is no balance column (ADR-062).
            'balance_minor' => $balance->balanceMinor,
            /*
            | THE TWO NUMBERS S3 SPLIT IT INTO (ADR-064). What is OWED and what is
            | PAYABLE stopped being the same figure when payout started waiting on
            | delivery: money from a parcel the buyer can still send back is real
            | and not yet drawable. Reported together so a screen cannot show one
            | and enforce the other.
            */
            'on_hold_minor' => $balance->onHoldMinor,
            'payable_minor' => $balance->payableMinor,
        ]);
    }
}
