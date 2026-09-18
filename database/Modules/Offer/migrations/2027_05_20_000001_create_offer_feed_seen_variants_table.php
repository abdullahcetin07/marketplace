<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a FULL-SYNC upload actually covered (Offer.md §19).
 *
 * **A FULL SYNC HAS TO KNOW WHAT THE FILE DID NOT SAY**, and neither the offers
 * nor the import row can answer that: an unchanged row saves nothing, so
 * `updated_at` cannot tell "the file repeated this price" from "the file never
 * mentioned it", and Filament's stored `file_path` points into Livewire's TEMP
 * directory, which is pruned. So the rows are recorded while they are being
 * processed, and the sweep at the end is a `NOT EXISTS` against them.
 *
 * **WRITTEN ONLY WHEN THE SELLER TICKED THE BOX.** An ordinary upload — the
 * common case — pays nothing for a feature it is not using.
 *
 * **VARIANT, NOT OFFER.** The importer already resolves the variant for every
 * row, including the rows that create an offer and the rows that change nothing,
 * so this costs no extra lookup. Deleted after the sweep, and pruned nightly for
 * the imports that never finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_feed_seen_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('import_id')->index();
            $table->uuid('variant_uuid');
            $table->timestamp('created_at')->nullable();

            // One row per variant per import: a file listing the same barcode
            // twice is a seller's mistake, not two sightings.
            $table->unique(['import_id', 'variant_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_feed_seen_variants');
    }
};
