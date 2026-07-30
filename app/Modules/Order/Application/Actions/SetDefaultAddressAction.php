<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\Models\CustomerAddress;

/**
 * Promote an address to the customer's default for shipping, billing, or both.
 *
 * PROMOTION, NOT TOGGLING. There is no way through this action to end up with no
 * default at all — a customer who has addresses always has one preselected at
 * checkout, and "none" is a state that only ever produces an extra question.
 *
 * ONE TRANSACTION FOR BOTH HALVES (BaseAction's), so a concurrent read can never
 * see the old default cleared and the new one not yet set. Small window, but it is
 * the window in which a checkout would find nothing preselected.
 *
 * @see docs/modules/Order.md §2.2
 */
final class SetDefaultAddressAction extends BaseAction
{
    public function __construct(
        private readonly CustomerAddressRepositoryContract $addresses,
    ) {}

    public function handle(mixed ...$arguments): CustomerAddress
    {
        /** @var CustomerAddress $address */
        $address = $arguments[0];
        /** @var bool $forShipping */
        $forShipping = $arguments[1] ?? true;
        /** @var bool $forBilling */
        $forBilling = $arguments[2] ?? true;

        $customerId = (int) $address->customer_id;
        $attributes = [];

        if ($forShipping) {
            $this->addresses->clearDefault($customerId, 'is_default_shipping', (int) $address->getKey());
            $attributes['is_default_shipping'] = true;
        }

        if ($forBilling) {
            $this->addresses->clearDefault($customerId, 'is_default_billing', (int) $address->getKey());
            $attributes['is_default_billing'] = true;
        }

        if ($attributes !== []) {
            $address->forceFill($attributes)->save();
        }

        return $address;
    }
}
