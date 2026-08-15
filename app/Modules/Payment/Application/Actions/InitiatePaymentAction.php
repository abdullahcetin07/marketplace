<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\InventoryReservationContract;
use App\Core\Domain\Contracts\LoyaltyContract;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Events\PaymentSucceeded;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Open a payment session for one checkout group (Payment.md §3, step 1).
 *
 * WHAT IT DOES NOT DO: take money. It creates the record that money will later be
 * attached to and asks the PSP for a token; the card, the 3-D Secure step and the
 * charge all happen inside PayTR's iframe, and the platform learns the outcome
 * from the callback. So nothing here is irreversible, which is what makes it safe
 * to retry.
 *
 * IT IS IDEMPOTENT BY CONSTRUCTION, and that is the property worth protecting
 * most: a buyer who double-clicks "öde", or reloads the payment page, must not
 * end up with two live PSP sessions for one basket — whichever they completed,
 * the other would sit holding stock until it expired. `payments.checkout_group_uuid`
 * is UNIQUE, so the second call finds the first payment instead of creating one.
 *
 * A NEW TOKEN EACH TIME, though, deliberately. PayTR's tokens expire, and a buyer
 * returning to an abandoned payment page needs one that works — so the row is
 * reused and the session is re-opened. `merchant_oid` stays the Payment uuid
 * either way, which is what keeps the callback able to recognise it.
 *
 * THE AMOUNT IS SUMMED FROM THE ORDERS, never passed in. A client-supplied amount
 * is the oldest vulnerability in e-commerce; the total is read through the Core
 * Order port from what was frozen at placement (ADR-053), so it is the number the
 * customer agreed to and nothing else.
 *
 * IT IMPORTS NO MODULE. Orders arrive through `OrderQueryContract`, the PSP
 * through `PaymentGatewayContract`, the currency through Localization — the
 * platform-wide exception.
 *
 * @see docs/modules/Payment.md §3
 */
final class InitiatePaymentAction extends BaseAction
{
    /** Emitted after commit, like every other event this module raises. */
    private ?PaymentSucceeded $paidWithPoints = null;

    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly PaymentGatewayContract $gateway,
        private readonly CurrencyRepositoryContract $currencies,
        /*
        | THE CORE PORT, NOT THE MODULE (ADR-084). Payment tells Loyalty to earmark
        | points and never learns what a ledger row is; `LayeringTest` fails the
        | build if either side names the other.
        */
        private readonly LoyaltyContract $loyalty,
        private readonly InventoryReservationContract $reservations,
    ) {}

    /**
     * @return array{payment: Payment, token: string}
     */
    public function handle(mixed ...$arguments): array
    {
        /** @var string $checkoutGroupUuid */
        $checkoutGroupUuid = $arguments[0];
        /** @var string $buyerIp */
        $buyerIp = $arguments[1] ?? '0.0.0.0';
        /** @var int $requestedPoints */
        $requestedPoints = (int) ($arguments[2] ?? 0);

        $orderUuids = $this->orders->ordersForCheckoutGroup($checkoutGroupUuid);
        $customer = $this->orders->checkoutGroupCustomer($checkoutGroupUuid);

        if ($orderUuids === [] || $customer === null) {
            /*
            | ONE ANSWER FOR "no such group" AND "not yours" — the rule every
            | public surface here keeps. The controller has already scoped the
            | lookup to the signed-in customer, so reaching this with a real
            | group means somebody is probing.
            */
            throw PaymentException::groupNotFound($checkoutGroupUuid);
        }

        $payment = Payment::query()->where('checkout_group_uuid', $checkoutGroupUuid)->first();

        if ($payment !== null && ! $payment->awaitsSettlement()) {
            // Already paid, or already refunded. Charging again is the single
            // worst thing this module could do, so it is refused before the PSP
            // is touched at all.
            throw PaymentException::alreadySettled($checkoutGroupUuid);
        }

        [$amountMinor, $basket] = $this->totalAndBasket($orderUuids);

        if ($amountMinor <= 0) {
            // Every order in the group was cancelled while the buyer sat on the
            // payment page. Charging zero would produce a "paid" order nobody paid
            // for.
            throw PaymentException::nothingToPay($checkoutGroupUuid);
        }

        $currency = $this->currencies->default();

        /*
        | **POINTS ARE EARMARKED BEFORE THE CARD IS TOUCHED** (ADR-084). The hold is
        | clamped against the LIVE balance and is idempotent per checkout group, so
        | a shopper who refreshes the payment page re-holds the same points rather
        | than stacking a second claim — and a balance that moved in another tab
        | reduces the discount here instead of overdrawing it.
        |
        | Held with zero points too: that RELEASES a hold from a previous attempt,
        | so a customer who unticks "Puanını kullan" and pays again is not still
        | occupying their own balance.
        */
        $heldPoints = $this->loyalty->hold($customer['uuid'], max(0, $requestedPoints), $checkoutGroupUuid);

        /*
        | **PRICED FOR THIS GROUP, IGNORING THIS GROUP'S OWN HOLD.** The points were
        | just earmarked a line above; quoting without excluding them would measure
        | the discount against a balance they have already left, and every
        | redemption would come back zero.
        */
        $quote = $this->loyalty->quote($customer['uuid'], $amountMinor, $heldPoints, $checkoutGroupUuid);

        $discountMinor = $quote['discount_minor'];
        $payableMinor = max(0, $amountMinor - $discountMinor);

        $payment ??= new Payment;

        $payment->fill([
            'checkout_group_uuid' => $checkoutGroupUuid,
            'customer_id' => $customer['id'],
            'customer_uuid' => $customer['uuid'],
            /*
            | **`amount_minor` IS THE CARD CHARGE, SO THE DISCOUNT REDUCES IT.** It
            | is what PayTR is asked for and what the callback verifies against; a
            | discount sitting beside it would make the two disagree and every
            | verified callback would be rejected as the wrong amount.
            |
            | Re-read every time: an order cancelled since the last attempt must not
            | still be charged for.
            */
            'amount_minor' => $payableMinor,
            'points_spent' => $quote['points_applied'],
            'discount_minor' => $discountMinor,
            'currency_id' => $currency->getKey(),
            'status' => PaymentStatus::Pending,
            'provider' => (string) config('payment.gateway', 'paytr'),
        ])->save();

        /*
        | **POINTS COVERED EVERYTHING: THERE IS NO CARD CHARGE** (ADR-084, and there
        | is no cap so this is reachable — owner's decision, 2026-08-15). PayTR
        | rejects a zero-amount order outright, so the gateway is skipped entirely
        | and the same success path the callback runs is invoked here instead.
        |
        | The payment keeps `amount_minor = 0`, which is precisely what a later
        | refund needs to know: there is no card money to send back, only points to
        | re-credit.
        */
        if ($payableMinor === 0) {
            return $this->settleWithPointsOnly($payment);
        }

        /*
        | THE PSP CALL IS INSIDE THE TRANSACTION, unusually for this codebase —
        | side effects normally live in `after()`. It is deliberate: the caller
        | needs the token in the same response, and a token belonging to a Payment
        | row that then rolled back would send a buyer to a payment page for a
        | record that does not exist. Repeat-safety is what `after()` protects, and
        | `initiate` is already safe to repeat: it opens a session, it takes no
        | money.
        */
        $session = $this->gateway->initiate(new PaymentIntentDTO(
            reference: $payment->merchantReference(),
            // THE REDUCED FIGURE. PayTR is asked for what the card must cover, and
            // the hash is built from it — sending the pre-discount total here would
            // charge the customer for points they just spent.
            amountMinor: $payableMinor,
            currencyCode: $currency->code,
            buyerEmail: $customer['email'],
            buyerName: $customer['email'],
            // Address and phone are PayTR form requirements, not data this module
            // owns: the real delivery address is snapshotted on each order, and
            // sending it here would put a home address in a third party's logs for
            // no benefit.
            buyerAddress: '-',
            buyerPhone: '-',
            buyerIp: $buyerIp,
            basket: $basket,
        ));

        return ['payment' => $payment->refresh(), 'token' => $session->token];
    }

    /**
     * Events after commit, like every other action here (`BaseAction::after()`).
     */
    protected function after(mixed $result, mixed ...$arguments): void
    {
        if ($this->paidWithPoints !== null) {
            event($this->paidWithPoints);
        }
    }

    /**
     * The success path when points covered the whole cart (ADR-084).
     *
     * **NO CARD, SO NO CALLBACK — AND THE CALLBACK IS NORMALLY THE TRUTH**
     * (ADR-060). There is no PSP to hear from, so this is the one place a payment
     * becomes paid without one, and it has to do everything that path does: commit
     * the stock, spend the points, mark it paid, announce it.
     *
     * **IT DELIBERATELY REPEATS `SettlePaymentCallbackAction::settle()` RATHER THAN
     * RESTRUCTURING IT.** That method is the verified-callback path for every real
     * charge on the platform; reshaping it to serve a case with no gateway result,
     * no hash and no amount to verify would put this feature's edge inside the code
     * that settles everybody's money. The duplication is named here so it is
     * maintained, and a test asserts both paths reach the same end state.
     *
     * @return array{payment: Payment, token: string|null}
     */
    private function settleWithPointsOnly(Payment $payment): array
    {
        foreach ($this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid) as $orderUuid) {
            foreach ($this->orders->reservationReferencesFor($orderUuid) as $reference) {
                try {
                    $this->reservations->commit($reference);
                } catch (Throwable $exception) {
                    /*
                    | LOGGED, NOT RETHROWN — the same judgement the callback path
                    | makes. The customer has paid; refusing to record that because
                    | one reservation would not commit leaves them charged with no
                    | order.
                    */
                    Log::channel('errors')->error('Could not commit a reservation on a points-only payment', [
                        'payment_uuid' => $payment->uuid,
                        'order_uuid' => $orderUuid,
                        'reference' => $reference,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->loyalty->commit($payment->checkout_group_uuid);

        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            // NAMED SO A HUMAN READING THE ROW KNOWS WHY THERE IS NO PSP REFERENCE.
            'provider_reference' => 'points',
            'paid_at' => now(),
        ])->save();

        $this->paidWithPoints = new PaymentSucceeded(
            $payment->uuid,
            $payment->checkout_group_uuid,
            // ZERO, and honestly so: no card was charged. Every listener that adds
            // up settlements must see the real figure, not the pre-discount total.
            $payment->amount_minor,
            $payment->currency->code,
            $this->orders->ordersForCheckoutGroup($payment->checkout_group_uuid),
        );

        // NO TOKEN: there is no iFrame to open, and the storefront reads the null
        // as "already paid, go to the thank-you page".
        return ['payment' => $payment->refresh(), 'token' => null];
    }

    /**
     * The group's total in kuruş, and the basket the buyer will see.
     *
     * SUMMED FROM `grand_total_minor`, which already includes KDV (ADR-042/055) —
     * so no tax is added here and none is recomputed. Integer arithmetic
     * throughout; there is no point in this chain where a float could appear.
     *
     * @param array<int, string> $orderUuids
     *
     * @return array{0: int, 1: array<int, array{name: string, price: string, quantity: int}>}
     */
    private function totalAndBasket(array $orderUuids): array
    {
        $total = 0;
        $basket = [];

        foreach ($orderUuids as $orderUuid) {
            $totals = $this->orders->orderTotals($orderUuid);

            if ($totals === null) {
                continue;
            }

            // A cancelled order contributes nothing. Read from the live status
            // rather than assumed, because the buyer may have been sitting on the
            // payment page while a seller cancelled.
            if ($this->orders->orderStatus($orderUuid) !== 'awaiting_payment') {
                continue;
            }

            $total += $totals['grand_total_minor'];

            foreach ($this->orders->orderLines($orderUuid) as $line) {
                $basket[] = [
                    'name' => $line['title'],
                    // PayTR wants a decimal string here — it is a LABEL on the
                    // payment page, not the amount charged. The charge is
                    // `payment_amount` in kuruş, computed above. Built with
                    // integer division so no float is constructed even for display.
                    'price' => intdiv($line['unit_price_minor'], 100)
                        .'.'.str_pad((string) ($line['unit_price_minor'] % 100), 2, '0', STR_PAD_LEFT),
                    'quantity' => $line['quantity'],
                ];
            }
        }

        return [$total, $basket];
    }
}
