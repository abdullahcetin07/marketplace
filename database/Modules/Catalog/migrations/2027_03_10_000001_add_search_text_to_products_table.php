<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The folded haystack a text search actually matches against.
 *
 * **WHY A COLUMN AND NOT AN EXPRESSION.** Diacritic folding has to happen on
 * BOTH sides of the comparison, and doing the haystack side in SQL means
 * `translate(lower(col), 'çğıöşü', 'cgiosu')` on Postgres and something else
 * entirely on SQLite, which has no `translate`. That driver split is exactly
 * what produced the false docblock this fixes — code that behaved differently
 * in the test suite than in production, and passed. Folding once on write, in
 * PHP, leaves one plain `LIKE` that means the same thing everywhere.
 *
 * **A CACHE, NOT A SOURCE OF TRUTH** — the same shape as `is_sellable`
 * (ADR-079). Titles, brand and category remain authoritative; this is derived
 * from them on save and rebuilt wholesale by `catalog:refresh-search-text`.
 * Drift is repaired by the sweep, never by a migration.
 *
 * NO INDEX, deliberately: the query is a leading-wildcard `LIKE`, which no
 * btree can serve. That is the same trade the browse already made against the
 * title columns, at the same catalogue size — and the reason tier 2 of the work
 * order wants a real engine rather than another column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            /*
            | Nullable, because a row is only folded once something writes it:
            | the backfill for the existing catalogue, the model hook for
            | everything after. NULL reads as "not folded yet" and simply fails
            | to match, which is the honest failure — the alternative, an empty
            | string default, would claim the row had been considered.
            */
            $table->text('search_text')->nullable()->after('is_sellable');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
