<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Models\CustomerAddress;

/**
 * Remove an address from the book.
 *
 * IT CANNOT ORPHAN AN ORDER, which is what makes it a comfortable thing to do
 * rather than a historical record nobody dares touch. A placed order holds its own
 * frozen copy of the address (ADR-053/056); this row is only ever the customer's
 * convenience.
 *
 * SOFT, because a customer may be looking at a list that still references it and a
 * hard delete under an open page is a 404 they did not cause.
 *
 * THE DEFAULT MOVES ON, rather than leaving the customer with none. Deleting the
 * default shipping address promotes whatever is left — an arbitrary choice, but a
 * strictly better one than a checkout with nothing preselected, and the customer
 * can change it in one click. When nothing is left there is nothing to promote,
 * and an empty book is the honest state.
 *
 * @see docs/modules/Order.md §2.2
 */
final class DeleteCustomerAddressAction extends BaseAction
{
    public function __construct(
        private readonly CustomerAddressRepositoryContract $addresses,
    ) {}

    public function handle(mixed ...$arguments): bool
    {
        /** @var CustomerAddress $address */
        $address = $arguments[0];

        $customerId = (int) $address->customer_id;
        $wasShipping = $address->is_default_shipping;
        $wasBilling = $address->is_default_billing;

        $deleted = (bool) $address->delete();

        if (! $deleted || (! $wasShipping && ! $wasBilling)) {
            return $deleted;
        }

        // `forCustomer()` excludes the soft-deleted row, so this is "whatever is
        // left" without having to say so.
        $replacement = $this->addresses->forCustomer($customerId)->first();

        if ($replacement === null) {
            return $deleted;
        }

        $replacement->forceFill(array_filter([
            'is_default_shipping' => $wasShipping ?: null,
            'is_default_billing' => $wasBilling ?: null,
        ]))->save();

        return $deleted;
    }
}
