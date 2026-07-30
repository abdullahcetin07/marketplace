<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * One address in the customer's book (ADR-056).
 *
 * ONE DTO FOR CREATE AND UPDATE, unlike the product's pair. An address is a small,
 * complete thing a customer retypes rather than patches — there is no field here
 * whose absence should mean "leave it alone", which is the only reason
 * `UpdateProductDTO` needs its `present` list.
 *
 * THE COUNTRY ARRIVES AS AN ISO CODE, not an id: this crosses an API boundary,
 * and internal ids never leave the application (non-negotiable #7). Resolving it
 * to Localization's row is the action's job.
 *
 * `label` is the customer's own name for it ("Ev", "Ofis") — the string that makes
 * a picker usable, and the reason an address book beats a single address field.
 */
final class CustomerAddressDTO extends BaseDTO
{
    public function __construct(
        public readonly string $label,
        public readonly string $recipientName,
        public readonly string $phone,
        public readonly string $line1,
        public readonly string $city,
        public readonly string $countryCode,
        public readonly ?string $line2 = null,
        public readonly ?string $district = null,
        public readonly ?string $postalCode = null,
        public readonly bool $isDefaultShipping = false,
        public readonly bool $isDefaultBilling = false,
    ) {}
}
