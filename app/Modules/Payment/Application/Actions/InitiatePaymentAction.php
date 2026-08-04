<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Core\Domain\Contracts\OrderQueryContract;
use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Payment\Domain\Contracts\PaymentGatewayContract;
use App\Modules\Payment\Domain\DTOs\PaymentIntentDTO;
use App\Modules\Payment\Domain\Enums\PaymentStatus;
use App\Modules\Payment\Domain\Exceptions\PaymentException;
use App\Modules\Payment\Domain\Models\Payment;

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
    public function __construct(
        private readonly OrderQueryContract $orders,
        private readonly PaymentGatewayContract $gateway,
        private readonly CurrencyRepositoryContract $currencies,
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

        $payment ??= new Payment;

        $payment->fill([
            'checkout_group_uuid' => $checkoutGroupUuid,
            'customer_id' => $customer['id'],
            'customer_uuid' => $customer['uuid'],
            // Re-read every time: an order cancelled since the last attempt must
            // not still be charged for.
            'amount_minor' => $amountMinor,
            'currency_id' => $currency->getKey(),
            'status' => PaymentStatus::Pending,
            'provider' => (string) config('payment.gateway', 'paytr'),
        ])->save();

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
            amountMinor: $amountMinor,
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
