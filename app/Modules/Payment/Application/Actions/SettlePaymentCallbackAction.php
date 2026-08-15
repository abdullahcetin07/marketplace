<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Core\Domain\Contracts\LoyaltyContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\GatewayResultDTO;
use App\Modules\Payment\Domain\DTOs\PaymentRefundDTO;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Events\PaymentFailed;
use App\Modules\Payment\Domain\Events\PaymentSucceeded;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The PSP's server-to-server callback — where a basket becomes a sale
 * (Payment.md §3 step 3, §5). **This is where ADR-054/057's promise is kept.**
 *
 * THE CALLBACK IS THE SOURCE OF TRUTH, NOT THE BROWSER REDIRECT. A buyer who pays
 * and then closes the tab must still get their order; this runs server-to-server
 * and does not care what the browser did. The redirect only decides what the buyer
 * sees.
 *
 * THREE PROPERTIES MAKE THIS SAFE, and all three are non-negotiable:
 *
 *  1. **HASH-VERIFIED.** The endpoint is public and unauthenticated, so a forged
 *     POST claiming "paid" is the obvious attack. The gateway recomputes the hash
 *     and this action refuses anything that does not verify — before it looks at
 *     the status at all.
 *  2. **IDEMPOTENT.** PayTR retries until it hears `"OK"`, so the same
 *     `merchant_oid` arrives many times. Processing happens once; every later
 *     arrival changes nothing and still answers OK. Without this, a retry would
 *     commit already-committed stock and, from P3, credit a seller twice.
 *  3. **ONE TRANSACTION.** Payment status, stock commit and the audit row move
 *     together or not at all. A crash between "paid" and "committed" would leave
 *     money taken and units still reserved, which nothing later could detect.
 *
 * IT RETURNS A BOOLEAN, NOT A RESPONSE, and the controller answers `"OK"`
 * regardless. That is not laziness: PayTR retries anything that is not `"OK"`
 * forever, and a payment this platform has already processed — or one it refuses
 * to believe — is not made truer by being asked again. What the boolean drives is
 * the log, not the wire.
 *
 * IT COMMITS THE STOCK BUT DOES NOT CONFIRM THE ORDERS. The commit is Payment's
 * by ADR-057; the order's status is Order's, moved by a class-string listener on
 * `PaymentSucceeded`. Payment imports no module and does not reach into another's
 * state machine.
 *
 * @see docs/modules/Payment.md §3, §5
 */
final class SettlePaymentCallbackAction extends BaseAction
{
    /**
     * Held between `handle()` and `after()` so the event fires AFTER COMMIT.
     *
     * @see the note on `after()`.
     */
    private ?PaymentSucceeded $succeeded = null;

    private ?PaymentFailed $failed = null;

    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly OrderQueryContract $orders,
        private readonly InventoryReservationContract $reservations,
        /*
        | The Core redemption port (ADR-084). Payment tells Loyalty to spend or
        | give back; neither module imports the other.
        */
        private readonly LoyaltyContract $loyalty,
    ) {}

    /**
     * @return bool whether this call is the one that settled the payment
     */
    public function handle(mixed ...$arguments): bool
    {
        /** @var array<string, mixed> $raw */
        $raw = $arguments[0];

        $result = $this->gateway->verifyCallback($raw);

        if (! $result->verified) {
            /*
            | A BAD HASH CHANGES NOTHING AND IS LOUD. Either the credentials are
            | misconfigured — in which case every real payment is silently failing
            | — or somebody is forging callbacks. Both deserve a look; neither
            | deserves a 500, because answering anything but OK makes PayTR retry
            | a payload we will never accept.
            */
            Log::channel('errors')->warning('Rejected a payment callback with an invalid hash', [
                'reference' => $result->reference,
                'remote_status' => $raw['status'] ?? null,
            ]);

            return false;
        }

        $payment = Payment::query()->where('uuid', $result->reference)->first();

        if ($payment === null) {
            // A verified hash for a payment we have no record of. Only reachable
            // if the merchant account is shared with another installation.
            Log::channel('errors')->warning('A verified callback named an unknown payment', [
                'reference' => $result->reference,
            ]);

            return false;
        }

        if (! $payment->awaitsSettlement()) {
            // THE RETRY PATH, and the common one. Not an error, not a second
            // charge — PayTR simply did not hear OK. Silence is the correct
            // response.
            return false;
        }

        return $result->successful
            ? $this->settle($payment, $result)
            : $this->fail($payment, $result);
    }

    /**
     * The events, dispatched AFTER COMMIT.
     *
     * Not inside the transaction, because a listener that credits a seller's
     * balance (P3) must never observe a payment a later failure rolls back — that
     * is the difference between an accounting record and a lie. `BaseAction::after()`
     * is exactly this hook.
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->succeeded !== null) {
            event($this->succeeded);
        }

        if ($this->failed !== null) {
            event($this->failed);
        }
    }

    /**
     * Take the holds back for any order that expired while the customer paid
     * (ADR-072).
     *
     * **ALL OR NOTHING, ACROSS THE WHOLE GROUP.** A basket is one charge
     * (ADR-052/060), so recovering three sellers' orders and failing the fourth
     * would leave the customer paid up and one seller unable to ship — and there
     * is no partial refund of a payment that was never split. Either every line
     * comes back or the charge goes back.
     *
     * **ANYTHING NOT `Expired` IS LEFT ALONE**, which is the ordinary case and
     * the reason this costs nothing on a normal payment: an `AwaitingPayment`
     * order still holds its reservation, and re-reserving it would double-count.
     *
     * WHAT IT RE-RESERVES, IT RELEASES ON FAILURE. A half-recovered group whose
     * charge is about to be refunded must not keep holding somebody's stock —
     * that would recreate, on the seller's side, exactly the leak this ADR
     * exists to close.
     *
     * @param array<int, string> $orderUuids
     */
    private function recoverExpiredHolds(array $orderUuids): bool
    {
        /** @var array<int, string> $taken */
        $taken = [];

        foreach ($orderUuids as $orderUuid) {
            /*
            | A STRING, NOT `OrderStatus::Expired` — Payment imports no module
            | (CLAUDE.md non-negotiable #2), so the value crosses the Core port as
            | text and `InitiatePaymentAction` compares `'awaiting_payment'` the
            | same way. `LayeringTest` fails the build on the tidier-looking
            | version.
            */
            if ($this->orders->orderStatus($orderUuid) !== 'expired') {
                continue;
            }

            $settlement = $this->orders->orderSettlement($orderUuid);
            $references = $this->orders->reservationReferencesFor($orderUuid);

            if ($settlement === null) {
                $this->releaseAll($taken);

                return false;
            }

            foreach ($this->orders->orderLines($orderUuid) as $line) {
                $reference = $references[$line['variant_uuid']] ?? null;

                if ($reference === null) {
                    $this->releaseAll($taken);

                    return false;
                }

                $reserved = false;

                try {
                    $reserved = $this->reservations->reserve(
                        $settlement['selling_org_uuid'],
                        $line['variant_uuid'],
                        $line['quantity'],
                        $reference,
                    );
                } catch (Throwable $exception) {
                    Log::channel('errors')->warning('Could not re-reserve a line for a late payment', [
                        'order_uuid' => $orderUuid,
                        'reference' => $reference,
                        'exception' => $exception->getMessage(),
                    ]);
                }

                if (! $reserved) {
                    // SOMEBODY ELSE BOUGHT IT. The only honest answer left is the
                    // customer's money.
                    $this->releaseAll($taken);

                    return false;
                }

                $taken[] = $reference;
            }
        }

        return true;
    }

    /**
     * Give back every hold this attempt took. @see `recoverExpiredHolds()`.
     *
     * @param array<int, string> $references
     */
    private function releaseAll(array $references): void
    {
        foreach ($references as $reference) {
            try {
                $this->reservations->release($reference);
            } catch (Throwable $exception) {
                Log::channel('errors')->warning('Could not release a hold taken during a failed recovery', [
                    'reference' => $reference,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * The stock is gone: give the money back and leave the orders expired
     * (ADR-072).
     *
     * **NEVER OVERSELL, NEVER KEEP A PAID CUSTOMER EMPTY-HANDED** — the two
     * halves of the rule, and this is the branch where they conflict. Somebody
     * else bought the last unit while this customer was at their bank's 3-D
     * Secure page; the platform cannot deliver, so the only honest outcome is a
     * full refund.
     *
     * **IT DOES NOT DISPATCH `PaymentSucceeded`.** That event is what moves
     * orders to `Paid`, and these orders cannot be fulfilled — `PaymentFailed`
     * carries the reason instead, which Order's listener logs and acts on
     * otherwise not at all, leaving them `Expired` where they belong.
     *
     * **THE PAYMENT LANDS IN A TERMINAL STATE, which is what makes the retry
     * safe.** PayTR retries until it hears OK, and `handle()` gates on
     * `awaitsSettlement()` — so a replayed callback finds a `Refunded` payment
     * and does nothing rather than refunding twice.
     *
     * A REFUND THE PSP REFUSES IS LOGGED AND STILL TERMINAL. Retrying it here
     * would mean holding the callback open against a provider that is already
     * saying no; the money is a human's problem at that point, and leaving the
     * payment settleable would be worse — it would let the next retry commit
     * stock nobody has.
     *
     * @param array<int, string> $orderUuids
     */
    private function refundUnfulfillable(Payment $payment, GatewayResultDTO $result, array $orderUuids): bool
    {
        $refunded = false;

        try {
            $refunded = $this->gateway->refund(new PaymentRefundDTO(
                // PayTR knows the charge by ONE name — the payment uuid (§4).
                reference: $payment->merchantReference(),
                amountMinor: $payment->amount_minor,
            ))->successful;
        } catch (Throwable $exception) {
            Log::channel('errors')->error('Could not refund a payment that arrived after expiry', [
                'payment_uuid' => $payment->uuid,
                'exception' => $exception->getMessage(),
            ]);
        }

        if (! $refunded) {
            Log::channel('errors')->error('A late payment could not be fulfilled OR refunded', [
                'payment_uuid' => $payment->uuid,
                'amount_minor' => $payment->amount_minor,
                'orders' => $orderUuids,
            ]);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Refunded,
            'provider_reference' => $result->providerReference,
            'provider_payload' => $result->toArray(),
            /*
            | `paid_at` IS STAMPED EVEN THOUGH THE MONEY WENT BACK, because it
            | did arrive — the charge is real and reconciliation against PayTR's
            | statement needs the timestamp. `Refunded` beside it says what
            | happened next; `failure_reason` says why.
            */
            'paid_at' => now(),
            'failure_reason' => 'expired_stock_unavailable',
        ])->save();

        $this->failed = new PaymentFailed(
            paymentUuid: $payment->uuid,
            checkoutGroupUuid: $payment->checkout_group_uuid,
            orderUuids: $orderUuids,
            reason: 'expired_stock_unavailable',
        );

        return false;
    }

    /**
     * Money arrived: mark it, and turn every held reservation into a real
     * decrement (ADR-057, Payment.md §5).
     */
    private function settle(Payment $payment, GatewayResultDTO $result): bool
    {
        /*
        | THE AMOUNT IS CHECKED, not assumed. A verified callback naming a
        | different total is not a payment for this basket — it is a
        | misconfiguration or a replayed payload from another order — and
        | accepting it would confirm orders nobody paid for. Integer comparison,
        | kuruş both sides, no tolerance: PayTR's unit is ours (ADR-005), so an
        | exact match is the only correct one.
        */
        if ($result->amountMinor !== $payment->amount_minor) {
            Log::channel('errors')->error('A verified callback named the wrong amount', [
                'payment_uuid' => $payment->uuid,
                'expected_minor' => $payment->amount_minor,
                'received_minor' => $result->amountMinor,
            ]);

            return false;
        }

        $orderUuids = $this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid);

        /*
        | **THE LATE-PAYMENT RACE (ADR-072).** The payment window is five minutes
        | and a slow 3-D Secure is longer, so a verified success can land for a
        | group whose orders have already expired and given their stock back.
        | Without this, the commit below would find no reservation, the Order
        | listener would find `Expired ↛ Paid`, and the platform would have taken
        | money for nothing.
        |
        | So the holds are taken back FIRST, and it is all-or-nothing: if every
        | line can be re-reserved the settlement proceeds exactly as normal and
        | the orders recover; if any line cannot — somebody else bought the last
        | one meanwhile — nothing is committed and the whole payment is refunded.
        | Half a basket is not an outcome anybody asked for.
        */
        if (! $this->recoverExpiredHolds($orderUuids)) {
            return $this->refundUnfulfillable($payment, $result, $orderUuids);
        }

        foreach ($orderUuids as $orderUuid) {
            foreach ($this->orders->reservationReferencesFor($orderUuid) as $reference) {
                try {
                    /*
                    | THE LINE ADR-057 WAS WRITTEN FOR. Placement only HELD these;
                    | this is the commit that makes the units truly leave. Keyed on
                    | Order's own `{order_uuid}:{variant_uuid}` STRING (the ADR-049
                    | amendment) — never a uuid, the trap this platform has met
                    | four times.
                    */
                    $this->reservations->commit($reference);
                } catch (Throwable $exception) {
                    /*
                    | ONE LINE'S COMMIT FAILING MUST NOT UNDO A COLLECTED PAYMENT.
                    | The money is real; refusing the whole callback would leave
                    | PayTR retrying against a payment that will never settle, and
                    | the buyer with a charge and no order. So it is logged for a
                    | human and the settlement continues — the ledger of record is
                    | Inventory's movement trail, which will show the gap.
                    */
                    Log::channel('errors')->error('Could not commit a reservation on payment success', [
                        'payment_uuid' => $payment->uuid,
                        'order_uuid' => $orderUuid,
                        'reference' => $reference,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        /*
        | **THE POINTS ARE SPENT HERE, BESIDE THE STOCK** (ADR-084). The
        | hash-verified callback is the truth (ADR-060), so this is the moment the
        | hold becomes a ledger row — and it is idempotent, because PayTR retries
        | and the same success may arrive three times.
        */
        $this->loyalty->commit($payment->checkout_group_uuid);

        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'provider_reference' => $result->providerReference,
            'provider_payload' => $result->toArray(),
            'paid_at' => now(),
        ])->save();

        $this->succeeded = new PaymentSucceeded(
            paymentUuid: $payment->uuid,
            checkoutGroupUuid: $payment->checkout_group_uuid,
            amountMinor: $payment->amount_minor,
            currencyCode: $payment->currency->code,
            orderUuids: $orderUuids,
        );

        return true;
    }

    /**
     * No money: mark it, and give the stock back.
     */
    private function fail(Payment $payment, GatewayResultDTO $result): bool
    {
        /*
        | **THE HELD POINTS GO BACK, AND NO LEDGER ROW IS WRITTEN** (ADR-084).
        | Nothing was spent, so nothing is recorded — the same judgement as the
        | reservation release below, and the reason a hold is not a ledger entry.
        */
        $this->loyalty->release($payment->checkout_group_uuid);

        $orderUuids = $this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid);

        /*
        | NO RECOVERY ATTEMPT HERE, and the asymmetry with `settle()` is the point.
        | A declined card for an expired order has nothing to take back — the holds
        | went back at expiry and the release below is a no-op on them. Re-reserving
        | for a payment that FAILED would hold a seller's units for a customer who
        | never paid, which is the leak ADR-072 exists to close, rebuilt by hand.
        */

        foreach ($orderUuids as $orderUuid) {
            foreach ($this->orders->reservationReferencesFor($orderUuid) as $reference) {
                try {
                    // Back on sale immediately. A declined card must not keep
                    // somebody else's units off the shelf for thirty minutes
                    // waiting for the expiry sweep.
                    $this->reservations->release($reference);
                } catch (Throwable $exception) {
                    Log::channel('errors')->warning('Could not release a reservation on payment failure', [
                        'payment_uuid' => $payment->uuid,
                        'reference' => $reference,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failure_reason' => $result->failureReason,
            'provider_payload' => $result->toArray(),
            'failed_at' => now(),
        ])->save();

        $this->failed = new PaymentFailed(
            paymentUuid: $payment->uuid,
            checkoutGroupUuid: $payment->checkout_group_uuid,
            orderUuids: $orderUuids,
            reason: $result->failureReason,
        );

        return true;
    }
}
