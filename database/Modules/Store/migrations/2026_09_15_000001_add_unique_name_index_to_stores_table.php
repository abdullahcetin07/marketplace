<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store names are unique platform-wide (owner-approved, see the Store freeze
 * notice).
 *
 * WHY. A buyer told to "shop at Beko" must land at one shop. Two storefronts
 * sharing a name makes every support ticket, invoice and complaint ambiguous,
 * and there is no second identifier a customer would ever quote.
 *
 * VALIDATED AT REQUEST TIME through `StoreQueryContract::storeNameExists()`, so
 * a seller hears "that name is taken" while filling the form rather than days
 * later when an admin approves. THIS INDEX IS THE GUARANTEE UNDER THAT CHECK:
 * two requests for the same name can sit in the queue simultaneously and be
 * approved seconds apart, and only the database can arbitrate that race.
 *
 * ON `LOWER(name)`, not on `name`. A case-sensitive index would let "Beko" and
 * "beko" both exist, which is precisely the collision a shopper cannot tell
 * apart. Postgres folds Turkish correctly here; SQLite's `LOWER` is ASCII-only,
 * so under the test suite "İSTANBUL" and "istanbul" remain two names — the same
 * driver difference `CatalogBrowse` documents, and harmless because production
 * is Postgres.
 *
 * PARTIAL on `deleted_at IS NULL`: a closed shop must not reserve its name
 * forever.
 *
 * EXISTING DUPLICATES ARE RENAMED, not left to fail the deploy. A migration
 * that aborts on real data is worse than one that resolves it visibly: each
 * later duplicate gains its own `store_number` — already unique, already
 * printed on the storefront — so the rename is traceable to the exact row and
 * the operator can rename it properly afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameDuplicates();

        DB::statement(
            'CREATE UNIQUE INDEX stores_name_unique '
            .'ON stores (LOWER(name)) '
            .'WHERE deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stores_name_unique');
    }

    /**
     * Give every duplicate after the first a name of its own.
     *
     * Ordered by id, so the ORIGINAL keeps the name and the later claimants are
     * the ones that change — the least surprising outcome for whoever registered
     * first.
     */
    private function renameDuplicates(): void
    {
        $rows = DB::table('stores')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'store_number']);

        $seen = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->name));

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }

            DB::table('stores')
                ->where('id', $row->id)
                ->update(['name' => $row->name.' ('.$row->store_number.')']);
        }
    }
};
