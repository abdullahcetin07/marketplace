<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * Changing how many of a basket line.
 *
 * AN ABSOLUTE QUANTITY, not a delta — "make it 3", never "add 1". A delta over
 * an unreliable network is how a double-tapped `+` button becomes five items,
 * and the client already knows the number it wants to show.
 */
final class UpdateCartItemDTO extends BaseDTO
{
    public function __construct(
        public readonly int $quantity,
    ) {}
}
