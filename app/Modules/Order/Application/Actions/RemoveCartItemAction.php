<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Models\CartItem;

/**
 * Take a line out of the basket.
 *
 * A HARD DELETE, and the only one in this module. Everything else here is either
 * soft-deleted (an address a customer may be looking at) or immutable (an order
 * line, ADR-053) — but a removed basket line is not a fact anybody will ever need
 * again, and keeping it would mean every read of a cart had to filter.
 *
 * NOTHING IS RELEASED, because nothing was held: a cart reserves no stock (see
 * `AddCartItemAction`). The basket only becomes a claim at checkout.
 *
 * THE CART SURVIVES an emptied basket. The customer will shop again, and a row per
 * purchase is churn.
 */
final class RemoveCartItemAction extends BaseAction
{
    public function handle(mixed ...$arguments): bool
    {
        /** @var CartItem $item */
        $item = $arguments[0];

        return (bool) $item->delete();
    }
}
