<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Commands;

use App\Modules\Catalog\Application\Services\ProductSellability;
use Illuminate\Console\Command;

/**
 * Rebuild `products.is_sellable` for the whole catalogue (ADR-079).
 *
 * **THE FLAG IS A CACHE AND THIS IS HOW IT HEALS.** Listeners keep it current for
 * everything that announces itself, but sellability also changes for reasons
 * nothing tells Catalog about: a store going dark, a reservation ageing out, a row
 * written by a fix script. A denormalised fact with no rebuild is a fact that
 * quietly diverges and needs a migration to correct.
 *
 * **IT IS ALSO THE BACKFILL.** The column ships defaulting to `false`, so nothing
 * is sellable until this has run once — wrong in the safe direction, and the
 * deploy step that makes the storefront correct.
 */
final class RefreshSellabilityCommand extends Command
{
    protected $signature = 'catalog:refresh-sellability';

    protected $description = 'Rebuild the products.is_sellable flag from the current offers, stores and stock';

    public function handle(ProductSellability $sellability): int
    {
        $result = $sellability->rebuild();

        $this->info(sprintf(
            'Sellable products: %d (%d rows changed).',
            $result['sellable'],
            $result['changed'],
        ));

        return self::SUCCESS;
    }
}
