<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\DTOs\UpdateCartItemDTO;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * Change how many of a basket line.
 *
 * AN ABSOLUTE QUANTITY, never a delta. A delta over an unreliable network is how
 * a double-tapped `+` becomes five items, and the client already knows the number
 * it means to show.
 *
 * IT DOES NOT RE-VALIDATE THE OFFER, deliberately. The offer was sellable when the
 * line was added; if it has since been paused or sold out, telling the customer at
 * the moment they adjust a quantity is worse than telling them at checkout, where
 * the whole basket is validated at once and they can act on all of it together
 * (§3.1). A basket that started rejecting edits to lines it already contains is a
 * basket a customer cannot empty.
 *
 * QUANTITY ZERO IS NOT A DELETE. `RemoveCartItemAction` exists and says what it
 * does; overloading zero would make "set it to what the box says" silently
 * destructive when a customer clears the field to retype it.
 *
 * @see docs/modules/Order.md §2.1
 */
final class UpdateCartItemAction extends BaseAction
{
    public function handle(mixed ...$arguments): CartItem
    {
        /** @var CartItem $item */
        $item = $arguments[0];
        /** @var UpdateCartItemDTO $data */
        $data = $arguments[1];

        $max = (int) config('order.cart.max_quantity_per_line', 100);

        if ($data->quantity < 1 || $data->quantity > $max) {
            throw OrderException::invalidQuantity($data->quantity, $max);
        }

        $item->forceFill(['quantity' => $data->quantity])->save();

        return $item;
    }
}
