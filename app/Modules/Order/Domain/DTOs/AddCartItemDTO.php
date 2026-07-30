<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Putting something in the basket.
 *
 * AN OFFER UUID, NOT A PRODUCT OR A VARIANT — because a shopper does not buy a
 * product, they buy ONE SELLER'S listing of it (ADR-042). Two sellers offering
 * the same variant at different prices are two different things to add, and the
 * buy box is what chose between them.
 *
 * NO PRICE FIELD, here or anywhere in the cart. What it costs is read live from
 * the Offer; a client that could send a price could set one (§2.1).
 */
final class AddCartItemDTO extends BaseDTO
{
    public function __construct(
        public readonly string $offerUuid,
        public readonly int $quantity = 1,
    ) {}
}
