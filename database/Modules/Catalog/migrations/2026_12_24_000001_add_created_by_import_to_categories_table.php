<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who made this category — the import, or a human? (ADR-075, amends ADR-047)
 *
 * **THE FLAG EXISTS TO TELL A DEFAULT FROM A DECISION.** `accepts_products = false`
 * means two different things depending on who set it. When the Category Manager
 * closes a node, that is a judgement about the shape of the catalogue and ADR-047
 * says an import must not overturn it. When the IMPORT closes a node, it is only
 * the "shelves, not shelves' contents" default it applied while walking a path —
 * nobody decided anything, and a later row selling directly at that node was
 * refused by a flag the import itself had set seconds earlier. Five rows failed
 * that way on the first real catalogue, and the rejection then drove 29,074 job
 * retries.
 *
 * **DEFAULT FALSE, WHICH MEANS "HUMAN-OWNED" FOR EVERY EXISTING ROW.** The
 * conservative direction: a category that exists today keeps ADR-047's behaviour
 * exactly, and only nodes created after this migration can ever be reopened
 * automatically.
 *
 * **IT IS CLEARED THE MOMENT A HUMAN EDITS THE CATEGORY**, so the column reads
 * precisely "the import made this and nobody has touched it since". Without that,
 * a re-import could reopen a node an admin had deliberately closed — which would
 * be ADR-047 broken by the back door.
 *
 * @see docs/modules/Catalog.md — bulk import
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('created_by_import')->default(false)->after('accepts_products');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('created_by_import');
        });
    }
};
