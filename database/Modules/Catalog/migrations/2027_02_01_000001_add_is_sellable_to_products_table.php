<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sellable fact, denormalised onto the product (ADR-079).
 *
 * **THE BROWSE COULD NOT AFFORD TO ASK.** "Sellable" means at least one active
 * offer, from a live store, with stock available — a fact that spans Offer,
 * Store and Inventory, and that the listing computed on every request by
 * collecting every sellable product uuid and handing them to a `whereIn`. At
 * 7,025 of them that is a query nobody should send, and it was the last second
 * of a page that had already been 22 seconds.
 *
 * **A CACHE, NOT A SOURCE OF TRUTH.** The offers, the stores and the movement
 * ledger remain authoritative; this column is derived from them, kept current by
 * listeners and rebuilt wholesale by `catalog:refresh-sellability`. Anything that
 * drifts is repaired by the sweep rather than by a migration.
 *
 * The index is composite with `status` because the browse always asks both, and
 * a published-but-unsold product is the common row it has to skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            /*
            | DEFAULT FALSE, so a product is invisible until something proves it
            | sellable. The opposite default would put every unsold product on the
            | storefront for the window between this migration and the backfill —
            | wrong in the direction that reaches buyers.
            */
            $table->boolean('is_sellable')->default(false)->after('status');

            $table->index(['status', 'is_sellable'], 'products_status_sellable_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_status_sellable_index');
            $table->dropColumn('is_sellable');
        });
    }
};
