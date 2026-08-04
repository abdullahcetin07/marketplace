<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\DTOs\GatewayRefundResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewayResultDTO;
use App\Modules\Payment\Domain\DTOs\GatewaySessionDTO;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;

/**
 * The whole PSP surface, so the domain never names one (ADR-060, Payment.md §3).
 *
 * A PORT, NOT A READ CONTRACT — and that makes it the odd one out among its
 * neighbours here. `CatalogQueryContract`, `OfferQueryContract` and the rest exist
 * so one module can ask another a question without importing it. This one exists
 * so the platform can talk to something OUTSIDE it, and the module on the far side
 * of the boundary is not a module at all. It is closest in shape to
 * `InventoryReservationContract`: three verbs, all of them commands.
 *
 * IT LIVES IN PAYMENT'S DOMAIN, NOT IN CORE — a deviation from Payment.md §3,
 * reported for amendment (2026-08-04). The spec places it in `app/Core`, but Core
 * may not name a module's types (`LayeringTest`: "Core never depends on a
 * module"), and this port's signatures are Payment's own DTOs. The three ways out
 * were: move the DTOs into Core, retype the port on plain arrays, or put the
 * interface where its vocabulary already lives. The third is the only one that
 * costs nothing.
 *
 * THE SPEC'S STATED REASON IS FULLY PRESERVED. §3 wants this port so "the domain
 * never names PayTR", and that holds identically here: the interface is what the
 * actions depend on, `PayTrGateway` is bound to it in the service provider, and
 * the only class on the platform that has heard of PayTR is in
 * `Payment/Infrastructure/Gateways`.
 *
 * THE CORE PLACEMENT WAS NEVER LOAD-BEARING because this is not a cross-module
 * port. Every contract in `app/Core/Domain/Contracts` exists so one module can ask
 * another a question without importing it; this one points OUT of the platform, at
 * a payment provider, and no other module will ever call it. It is closest in kind
 * to `CategorySlugGeneratorContract` — a module's own port to its own
 * infrastructure — which lives in its module for the same reason.
 *
 * NO CARD DATA CROSSES IT, EVER. `initiate()` returns a token the buyer's browser
 * exchanges for an iframe; the card and its 3-D Secure step happen inside the
 * PSP's page. What comes back is a reference and an outcome. There is no method
 * here that could carry a PAN, and that is deliberate rather than incidental.
 *
 * @see App\Modules\Payment\Infrastructure\Gateways\PayTrGateway
 * @see docs/modules/Payment.md §3
 */
interface PaymentGatewayContract
{
    /**
     * Open a payment session and return what the buyer's browser needs.
     *
     * The intent carries OUR reference (`merchant_oid` = the Payment uuid), which
     * is what makes the whole flow idempotent: the PSP echoes it back on every
     * callback, including its retries, so the platform can recognise a payment it
     * has already processed.
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\PaymentException when the PSP
     *                                                                 refuses or is unreachable
     */
    public function initiate(PaymentIntentDTO $intent): GatewaySessionDTO;

    /**
     * Turn a raw callback payload into a verified result.
     *
     * THE HASH CHECK LIVES HERE, not in the controller, because it is the one
     * thing that makes this endpoint safe: the callback is public and
     * unauthenticated, so a forged POST claiming "paid" is the obvious attack. An
     * implementation that cannot verify the payload MUST return a failed result
     * rather than trusting it.
     *
     * @param array<string, mixed> $raw exactly what the PSP posted
     */
    public function verifyCallback(array $raw): GatewayResultDTO;

    /**
     * Reverse a settled payment, in full or in part (P5).
     *
     * @throws \App\Modules\Payment\Domain\Exceptions\PaymentException
     */
    public function refund(PaymentRefundDTO $refund): GatewayRefundResultDTO;
}
