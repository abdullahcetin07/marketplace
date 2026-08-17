<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Controllers\Api\Storefront;

use App\Core\Presentation\Controllers\BaseController;
use App\Modules\Loyalty\Presentation\Requests\EarnPreviewRequest;
use Illuminate\Http\JsonResponse;

/**
 * "Bu ürünü alınca kaç puan kazanırsın?" (ADR-082)
 *
 * **THE BACKEND DOES THE ARITHMETIC BECAUSE THE FRONTEND MUST NOT.** A price
 * crosses as a decimal string (ADR-005), and a storefront that multiplied it by a
 * rate would be doing money maths in JavaScript floats — and would disagree with
 * what the sweep actually credits the day either side rounds differently.
 *
 * **THE SAME FLOOR AS THE SWEEP, DELIBERATELY.** `floor(TL × rate)` is what
 * `AwardPurchasePointsAction` computes; a preview that rounded up would promise a
 * point the customer never receives, which is worse than promising nothing.
 *
 * **PUBLIC, BECAUSE THE PRODUCT PAGE IS.** A signed-out shopper is exactly who this
 * card is arguing with — "üye ol, bu sepet sana 149 puan kazandırsın" is the pitch,
 * and requiring a login to see it would defeat it. It reads only `settings()`: no
 * ledger, no customer, no cart, nothing to leak.
 *
 * @see App\Modules\Loyalty\Application\Actions\AwardPurchasePointsAction
 */
final class EarnPreviewController extends BaseController
{
    /**
     * GET /api/v1/loyalty/earn-preview?amount=129.90
     */
    public function show(EarnPreviewRequest $request): JsonResponse
    {
        if (! settings('loyalty.enabled', true)) {
            /*
            | OFF IS AN ANSWER, NOT AN ERROR. The storefront renders nothing when
            | `enabled` is false; a 404 or a 422 would make a switched-off programme
            | look like a broken page.
            */
            return $this->ok(['enabled' => false, 'points' => 0, 'currency' => 'TRY']);
        }

        $rate = (float) settings('loyalty.earn.purchase_rate', 1);

        return $this->ok([
            'enabled' => true,
            // Whole lira in, whole points out — the sweep's arithmetic, to the digit.
            'points' => (int) floor($request->amountMinor() / 100 * $rate),
            'currency' => 'TRY',
        ]);
    }
}
