<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers\Api\Customer;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
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
    public function __construct(private readonly LoyaltyLedgerRepositoryContract $ledger) {}

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
}
