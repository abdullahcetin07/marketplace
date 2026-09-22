<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Contracts\CartRepositoryContract;
use App\Modules\Order\Domain\DTOs\AddCartItemDTO;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Domain\Models\OrderLine;
use Illuminate\Support\Facades\Log;

/**
 * A checkout that never became a purchase gives the basket back (Order.md §13).
 *
 * **BOTH ENDINGS, ONE PATH.** A card is declined and PayTR tells us; or the
 * shopper closes the tab at the payment form and PayTR never says anything at
 * all, so the order sits until the sweep expires it (ADR-072). The second is the
 * commoner ending and it looked identical to the shopper — an empty cart — so
 * restoring only the first would have left the promise half true.
 *
 * **CHECKOUT EMPTIES THE CART, AND UNTIL 2026-09-22 NOTHING EVER REFILLED IT.**
 * The shopper was left with no cart, an order they could only cancel, and a
 * failure page promising "Sepetiniz duruyor" — which pointed at an empty one. Of
 * the 16 customers who hit it on production, 3 ever completed a purchase
 * afterwards. Retrying is the single most likely thing a declined shopper does,
 * and the platform made it the hardest.
 *
 * **IT REVERSES THE CHECKOUT, IT DOES NOT UNDO THE ORDER.** The lines go back to
 * the cart and the orders are EXPIRED in the same transaction — one basket in one
 * place, never a live order beside a full cart that could be checked out twice.
 * Expiry is reused rather than cancellation: `Cancelled` says a person ended
 * this, and nobody did (ADR-072's distinction), and `ExpireOrderAction` already
 * releases the holds idempotently.
 *
 * **IT DRIVES `AddCartItemAction`, NOT THE CART MODEL.** That action re-reads the
 * offer through `OfferQueryContract`, so a line whose seller withdrew or sold out
 * while the card was being declined is REFUSED rather than restored as something
 * unbuyable — and the price the shopper meets is today's, because a cart stores
 * no prices (ADR-053's boundary, the whole reason the two classes are separate).
 *
 * **BEST EFFORT, PER LINE.** One dead offer must not cost the shopper the other
 * nine items; a refusal is logged and skipped. Returning the basket partly is
 * strictly better than returning none of it.
 *
 * **TWICE IS THE NORMAL CASE, NOT THE EDGE.** PayTR retries its callback until it
 * hears OK, so this runs again on a payload it has already handled. Two guards,
 * either sufficient: only `AwaitingPayment` orders are touched (the first pass
 * expires them), and a cart line that already names the offer is left exactly as
 * the shopper has it — their own edit outranks a restore.
 */
final class RestoreCartFromUnpaidCheckoutAction extends BaseAction
{
    public function __construct(
        private readonly CartRepositoryContract $carts,
        private readonly AddCartItemAction $addItem,
        private readonly ExpireOrderAction $expire,
    ) {}

    /**
     * @return int the number of lines put back
     */
    public function handle(mixed ...$arguments): int
    {
        /** @var string $checkoutGroupUuid */
        $checkoutGroupUuid = $arguments[0];

        /** @var array<int, Order> $orders */
        $orders = Order::query()
            ->with('lines')
            ->where('checkout_group_uuid', $checkoutGroupUuid)
            ->where('status', OrderStatus::AwaitingPayment->value)
            ->get()
            ->all();

        if ($orders === []) {
            // Already handled, already paid, or already expired by the sweep.
            return 0;
        }

        $restored = 0;

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                if ($this->restore($order, $line)) {
                    $restored++;
                }
            }

            $this->expire->run($order);
        }

        return $restored;
    }

    /**
     * One line back into the basket, or not at all.
     */
    private function restore(Order $order, OrderLine $line): bool
    {
        $offerUuid = (string) $line->offer_uuid;

        $cart = $this->carts->forCustomer((int) $order->customer_id);

        if ($cart !== null && $this->carts->findItemForOffer($cart, $offerUuid) !== null) {
            // The shopper already put this back themselves while the card was
            // being declined. Adding would silently double their quantity.
            return false;
        }

        try {
            $this->addItem->run(
                (int) $order->customer_id,
                (string) $order->customer_uuid,
                new AddCartItemDTO(offerUuid: $offerUuid, quantity: (int) $line->quantity),
            );

            return true;
        } catch (OrderException $exception) {
            /*
            | Sold out, withdrawn, or a basket already at its line limit. Not an
            | incident — it is the catalogue having moved on — but it is the one
            | reason a shopper sees fewer items than they had, so it is written
            | down where support can find it.
            */
            Log::channel('errors')->info('A line could not be restored to the cart after a failed payment', [
                'order_uuid' => $order->uuid,
                'offer_uuid' => $offerUuid,
                'reason' => $exception->getContext()['reason'] ?? null,
            ]);

            return false;
        }
    }
}
