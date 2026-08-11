<?php

declare(strict_types=1);

namespace App\Modules\Offer\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * What one feed item did (ADR-076).
 *
 * **`Unchanged` IS A SUCCESS, AND IT IS THE POINT OF HAVING THIS ENUM.** A seller
 * pushes their whole catalogue every morning and most of it did not move overnight;
 * the feed must be able to say "I read it and there was nothing to do" without
 * emitting `OfferStockChanged` to Inventory and the search index for four thousand
 * unchanged rows. A boolean success/failure could not express that, so the loudest
 * consumers would be woken by silence.
 *
 * FAILURE IS NOT A CASE HERE. It rides `OfferFeedException`, which carries the
 * machine reason the caller needs — an enum case would flatten five distinct
 * refusals into one word.
 *
 * Module-owned, no `Enum` suffix (ADR-007).
 */
enum OfferFeedOutcome: string
{
    use HasEnumHelpers;

    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
}
