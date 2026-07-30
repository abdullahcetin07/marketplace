<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\DTOs;

use App\Core\Domain\DataTransferObjects\BaseDTO;

/**
 * The line below which a seller wants to hear about a stock pool (§3.3).
 *
 * NULL MEANS "STOP TELLING ME", not "use a default". A seller who never asked
 * to be warned should hear nothing, and a platform-wide default would page
 * every seller about every slow-moving variant they deliberately keep one of.
 *
 * ZERO IS A LEGITIMATE THRESHOLD — "tell me when I actually run out" — which is
 * why every check for a threshold tests against null rather than falsiness.
 */
final class SetLowStockThresholdDTO extends BaseDTO
{
    public function __construct(
        public readonly ?int $threshold,
    ) {}
}
