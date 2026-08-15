<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers\Api\Customer;

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Presentation\Requests\RedeemQuoteRequest;
use App\Modules\Loyalty\Presentation\Resources\LoyaltyLedgerEntryResource;
use App\Shared\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Puanlarım" — a customer's balance and history (ADR-081).
 *
 * **THE CALLER IS THE SUBJECT, AND THERE IS NO PARAMETER FOR ANYBODY ELSE.** No
 * `{customer}` in the path and no id in the query: the only ledger these routes
 * can read is the authenticated customer's, so reading somebody else's is not
 * denied — it is unexpressible. That is the same construction the profile routes
 * use, and it is stronger than a policy.
 *
 * **CUSTOMERS ONLY.** A seller or an admin holding a session is refused rather
 * than shown an empty balance: an empty balance implies the programme applies to
 * them and merely has nothing in it.
 */
final class LoyaltyController extends BaseController
{
    public function __construct(
        private readonly LoyaltyLedgerRepositoryContract $ledger,
        private readonly LoyaltyContract $redemption,
        private readonly OrderQueryContract $orders,
    ) {}

    /**
     * GET /api/v1/loyalty/balance
     */
    public function balance(): JsonResponse
    {
        $customerUuid = $this->customerUuid();

        $points = $this->ledger->balanceFor($customerUuid);

        return $this->ok([
            'points' => $points,
            /*
            | **A DECIMAL STRING, COMPUTED FROM THE CURRENT VALUE** (ADR-005). What
            | a point is worth is a setting an operator can change, so this number
            | is what today's balance is worth today — not a total anybody has
            | banked. The points count is the durable fact; the lira are a
            | rendering of it.
            */
            'value' => $this->valueOf($points),
            'currency' => 'TRY',
        ]);
    }

    /**
     * GET /api/v1/loyalty/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $customerUuid = $this->customerUuid();

        $entries = $this->ledger->historyFor($customerUuid, $this->perPage(default: 20, max: 50));

        return $this->ok(
            LoyaltyLedgerEntryResource::collection($entries->items())->resolve($request),
            null,
            [
                'current_page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
            ],
        );
    }

    /**
     * POST /api/v1/loyalty/redeem/quote
     *
     * **A PREVIEW, AND NOTHING MOVES.** The checkout page calls this on every drag
     * of the slider; holding points here would earmark a balance on a page the
     * shopper may close, and releasing them would need a sweep for something the
     * pay step already does properly.
     *
     * **THE CART IS PRICED LIVE** (ADR-052: a cart stores no prices), so the total
     * this clamps against is the one the shopper is about to be charged rather than
     * one that was true when they added the item.
     */
    public function quote(RedeemQuoteRequest $request): JsonResponse
    {
        $customerUuid = $this->customerUuid();

        $cartTotal = $this->orders->activeCartTotalFor($customerUuid);

        $quote = $this->redemption->quote($customerUuid, $cartTotal, $request->requestedPoints());

        return $this->ok([
            'points_applied' => $quote['points_applied'],
            // Decimal strings on the wire, minor units inside (ADR-005).
            'discount' => $this->decimal($quote['discount_minor']),
            'cart_total' => $this->decimal($cartTotal),
            'payable' => $this->decimal($quote['payable_minor']),
            'currency' => 'TRY',
            // What the slider's maximum should be — the smaller of the balance and
            // what the basket can absorb.
            'max_points' => $quote['max_points'],
        ]);
    }

    /**
     * The signed-in customer's uuid, or a refusal.
     */
    private function customerUuid(): string
    {
        $actor = current_actor();

        if ($actor === null || $actor->type !== UserType::Customer) {
            throw new AccessDeniedHttpException;
        }

        return (string) $actor->uuid;
    }

    private function valueOf(int $points): string
    {
        $perPoint = (float) settings('loyalty.redeem.value', 0.05);

        return number_format($points * $perPoint, 2, '.', '');
    }

    private function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
