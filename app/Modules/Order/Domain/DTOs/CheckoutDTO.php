<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * What a customer supplies to turn a basket into orders (ADR-052/056).
 *
 * STRIKINGLY SMALL, and that is the design: everything else checkout needs — the
 * lines, the prices, the tax rates, the sellers — is read from the cart and from
 * the other modules at the moment of checkout. What the CUSTOMER contributes is
 * only where it goes and who it is invoiced to.
 *
 * TWO ADDRESSES, SEPARATELY, and both required (ADR-056): "deliver to the office,
 * invoice the company" is ordinary, not an edge case. A client that wants them to
 * be the same sends the same uuid twice, which is explicit — inferring "billing =
 * shipping when omitted" would silently put a home address on a company's invoice.
 *
 * BY UUID, because this comes off an HTTP request (non-negotiable #7). The action
 * re-checks that both belong to the acting customer: a scoped picker is a UI
 * convenience, not a security boundary.
 */
final class CheckoutDTO extends BaseDTO
{
    public function __construct(
        public readonly string $shippingAddressUuid,
        public readonly string $billingAddressUuid,
    ) {}
}
