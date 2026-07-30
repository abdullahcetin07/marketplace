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
 * Add an address to the customer's book (ADR-056).
 *
 * THE FIRST ADDRESS BECOMES BOTH DEFAULTS, whatever the payload says. A customer
 * with exactly one address and no defaults would be asked to choose between one
 * option at checkout — a question with no information in it — and every client
 * would have to special-case the empty book. Deciding it here means neither the
 * API nor a future storefront has to.
 *
 * SETTING A DEFAULT CLEARS THE PREVIOUS ONE, inside this action's transaction.
 * That is the application half of the "one default per purpose" guarantee; the
 * partial unique index behind it exists only on PostgreSQL (see the migration),
 * so this is the half that holds on every engine and the half the suite exercises.
 *
 * THE COUNTRY ARRIVES AS AN ISO CODE and is resolved here. Internal ids never
 * cross a boundary (non-negotiable #7), and Localization is the one module every
 * other may read (§5.1).
 *
 * @see docs/modules/Order.md §2.2
 */
final class CreateCustomerAddressAction extends BaseAction
{
    public function __construct(
        private readonly CustomerAddressRepositoryContract $addresses,
    ) {}

    public function handle(mixed ...$arguments): CustomerAddress
    {
        /** @var int $customerId */
        $customerId = $arguments[0];
        /** @var string $customerUuid */
        $customerUuid = $arguments[1];
        /** @var CustomerAddressDTO $data */
        $data = $arguments[2];

        $country = Country::query()->where('iso2', mb_strtoupper($data->countryCode))->first();

        if ($country === null) {
            // Reuses the address refusal rather than inventing a country one: from
            // the customer's side "we could not save this address" is the fact,
            // and the field-level message belongs to form validation.
            throw OrderException::addressNotFound($data->countryCode);
        }

        $isFirst = $this->addresses->forCustomer($customerId)->isEmpty();

        $shipping = $data->isDefaultShipping || $isFirst;
        $billing = $data->isDefaultBilling || $isFirst;

        if ($shipping) {
            $this->addresses->clearDefault($customerId, 'is_default_shipping');
        }

        if ($billing) {
            $this->addresses->clearDefault($customerId, 'is_default_billing');
        }

        $address = new CustomerAddress;
        $address->fill([
            'customer_id' => $customerId,
            'customer_uuid' => $customerUuid,
            'label' => $data->label,
            'recipient_name' => $data->recipientName,
            'phone' => $data->phone,
            'line1' => $data->line1,
            'line2' => $data->line2,
            'district' => $data->district,
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
