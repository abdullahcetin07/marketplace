<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers\Api;

use App\Modules\Payment\Application\Actions\SettlePaymentCallbackAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * PayTR's server-to-server callback (Payment.md §3 step 3).
 *
 * IT ANSWERS `"OK"` TO EVERYTHING, AND THAT IS THE WHOLE DESIGN. PayTR retries any
 * response that is not exactly `OK` — for days — so a 500 on a payload the
 * platform will never accept becomes an unbounded retry storm, and a 422 on a
 * duplicate makes PayTR keep re-sending a payment already settled. Every outcome
 * that is not "please send this again" is `"OK"`.
 *
 * SO THE HTTP LAYER CARRIES NO MEANING HERE, unusually. Whether the payment was
 * accepted, rejected as forged, or recognised as a retry is recorded in the audit
 * trail and the logs, not in the status code. The one thing the status code says
 * is "stop retrying".
 *
 * IT DOES NOT EXTEND `BaseController`, deliberately: that base wraps responses in
 * the ADR-009 envelope, and PayTR wants three bytes of plain text. An envelope
 * here would be a permanently-retrying integration.
 *
 * UNAUTHENTICATED — it must be, since PayTR has no session — which is exactly why
 * the HASH is the security model. Verification happens in the gateway before the
 * action looks at anything, and a forged POST changes nothing.
 *
 * EVEN AN EXCEPTION ANSWERS OK. If this platform is broken, PayTR retrying for two
 * days will not fix it, and the retries would bury the error that matters. The
 * throwable is logged and the integration is told to move on.
 *
 * @see docs/modules/Payment.md §3
 */
final class PayTrCallbackController extends Controller
{
    public function __construct(private readonly SettlePaymentCallbackAction $settle) {}

    /**
     * POST /api/v1/payments/paytr/callback
     */
    public function __invoke(Request $request): Response
    {
        try {
            $this->settle->run($request->all());
        } catch (Throwable $exception) {
            report($exception);
        }

        return response('OK')->header('Content-Type', 'text/plain');
    }
}
