<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Actions;

use App\Core\Application\Actions\BaseAction;
use App\Modules\Localization\Domain\Models\Country;
use App\Modules\Order\Domain\Contracts\CustomerAddressRepositoryContract;
use App\Modules\Order\Domain\DTOs\CustomerAddressDTO;
use App\Modules\Order\Domain\Exceptions\OrderException;
use App\Modules\Order\Domain\Models\CustomerAddress;

/**
 * Edit an address in the book.
 *
 * EDITING IS SAFE HERE IN A WAY IT IS NOWHERE ELSE IN THIS MODULE, and that is
 * the whole reason the book and the order snapshot are separate things (ADR-053/
 * 056). A placed order holds its own frozen copy, so a customer who moves house
 * changes where their NEXT parcel goes and nothing about where the last one went.
 * If this table were referenced by an order, this action could not exist.
 *
 * A FULL REPLACEMENT, not a patch. An address is a small, complete thing a
 * customer retypes rather than amends field by field — there is nothing here whose
 * absence should mean "leave it alone", which is the only reason
 * `UpdateProductDTO` needs its `present` list.
 *
 * DEMOTING IS NOT SUPPORTED, and that is deliberate: passing `isDefaultShipping:
 * false` on the current default would leave the customer with NO default and a
 * checkout that has nothing to preselect. A default moves by promoting another
 * address, which is what `SetDefaultAddressAction` is for.
 *
 * @see docs/modules/Order.md §2.2
 */
final class UpdateCustomerAddressAction extends BaseAction
{
    public function __construct(
        private readonly CustomerAddressRepositoryContract $addresses,
    ) {}

    public function handle(mixed ...$arguments): CustomerAddress
    {
        /** @var CustomerAddress $address */
        $address = $arguments[0];
        /** @var CustomerAddressDTO $data */
        $data = $arguments[1];

        $country = Country::query()->where('iso2', mb_strtoupper($data->countryCode))->first();

        if ($country === null) {
            throw OrderException::addressNotFound($data->countryCode);
        }

        $customerId = (int) $address->customer_id;

        // Promotions only — see the class docblock on why demotion is absent.
        $shipping = $data->isDefaultShipping || $address->is_default_shipping;
        $billing = $data->isDefaultBilling || $address->is_default_billing;

        if ($shipping && ! $address->is_default_shipping) {
            $this->addresses->clearDefault($customerId, 'is_default_shipping', (int) $address->getKey());
        }

        if ($billing && ! $address->is_default_billing) {
            $this->addresses->clearDefault($customerId, 'is_default_billing', (int) $address->getKey());
        }

        $address->fill([
            'label' => $data->label,
            'recipient_name' => $data->recipientName,
            'phone' => $data->phone,
            'line1' => $data->line1,
            'line2' => $data->line2,
            'district' => $data->district,
            'neighborhood' => $data->neighborhood,
            'city' => $data->city,
            'postal_code' => $data->postalCode,
            'country_id' => $country->getKey(),
            'is_default_shipping' => $shipping,
            'is_default_billing' => $billing,
        ]);
        $address->save();

        return $address;
    }
}
