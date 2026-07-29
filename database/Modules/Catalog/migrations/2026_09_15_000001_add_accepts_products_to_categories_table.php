<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-047 — product attachment stops being a consequence of tree shape and
 * becomes an explicit flag the Category Manager owns.
 *
 * WHAT CHANGES. ADR-038 let a product attach to a **leaf**, which meant the
 * taxonomy's shape decided where products could go: adding one sub-category to
 * *Makyaj* silently made *Makyaj* unable to hold products. This column makes the
 * decision explicit, so a flagged category may have children and still sell.
 *
 * THE DATA MIGRATION IS THE WHOLE POINT OF THE `false` DEFAULT. Every existing
 * leaf is flagged `true` so today's products keep validating tomorrow; every
 * existing container stays `false`, which is exactly what the leaf rule already
 * enforced. Nothing changes behaviour on deploy — the flag only becomes
 * interesting when a Category Manager turns one on.
 *
 * A SET-BASED UPDATE, not a loop over the tree. "Has no children" is one
 * `NOT EXISTS` against the same table, so this stays a single statement on a
 * taxonomy of any size.
 *
 * @see docs/modules/Catalog.md §3.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            /*
            | Indexed: the Category Manager's UI filters on it, and once
            | seller-facing pickers only offer flagged categories it becomes a
            | predicate on a hot read.
            |
            | Default FALSE so a category created after this migration must be
            | flagged deliberately. A `true` default would quietly re-create the
            | problem ADR-047 solves, in reverse — every new container would
            | accept products until somebody noticed.
            */
            $table->boolean('accepts_products')->default(false)->index()->after('is_active');
        });

        /*
        | Preserve today's behaviour exactly: a category with no children is
        | what the leaf rule already allowed products on.
        |
        | Inactive children count, deliberately — the same reasoning
        | `Category::isLeaf()` used. Reactivating a hidden child must not
        | retroactively orphan products attached while it was invisible.
        */
        DB::table('categories')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('categories as children')
                    ->whereColumn('children.parent_id', 'categories.id');
            })
            ->update(['accepts_products' => true]);
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('accepts_products');
        });
    }
};
