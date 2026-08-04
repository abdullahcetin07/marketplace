<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The offers table — a seller org's price and stock for one catalog variant
 * (ADR-042, Offer.md §2.1).
 *
 * NO FOREIGN KEYS TO THE CATALOG, THE ORGANIZATION OR THE STORE. Every
 * cross-context reference is the ADR-040/033 pair: a uuid for identity and, only
 * where a tenancy filter genuinely needs one, an internal id. A FK would mean a
 * join across a bounded-context boundary and, worse, would tempt someone to add
 * the relation the model deliberately refuses. The one real FK is `currency_id`
 * — Localization is platform-wide reference data.
 *
 * MONEY IS `bigInteger` MINOR UNITS (non-negotiable #6). Never DECIMAL, never a
 * float: DECIMAL is reserved for rates and percentages (ADR-005). `bigInteger`
 * rather than `integer` because 2.1 billion kuruş is only ₺21m — a plausible
 * ceiling for a B2B listing, and widening a money column on a hot table later is
 * the kind of migration nobody wants to schedule.
 *
 * `product_uuid` IS DENORMALIZED from the variant on purpose (§2.1): the buy box
 * groups by product, and resolving variant→product through the Catalog on every
 * public read would put a cross-context call inside the hottest query on the
 * platform. It is written once, at create, from
 * `CatalogQueryContract::productUuidForVariant()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The catalog references — uuid only, no FK (ADR-040).
            $table->uuid('variant_uuid');
            $table->uuid('product_uuid');

            /*
            | The selling company. The internal id is what the seller panel's
            | tenancy wall filters on (`organizationIdsForUser()` speaks internal
            | ids); the uuid is what every public payload and event carries. Both,
            | because neither alone does both jobs (ADR-040).
            */
            $table->unsignedBigInteger('selling_org_id');
            $table->uuid('selling_org_uuid');

            // The storefront this offer is attributed to (ADR-046).
            $table->uuid('store_uuid');

            $table->bigInteger('price_minor');
            // The struck-through comparison price. Nullable because most offers
            // are not on sale, and 0 would be a lie rather than an absence.
            $table->bigInteger('list_price_minor')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // ADR-043: a plain counter this sprint. Unsigned so "negative stock"
            // is impossible at the storage layer, not just in validation.
            $table->unsignedInteger('stock_quantity')->default(0);

            $table->string('status', 20)->index();
            // Restored verbatim on reinstatement, so lifting a suspension undoes
            // the admin's action rather than overriding the seller's (§3.1).
            $table->string('status_before_suspension', 20)->nullable();
            /*
            | Whether Catalog's product lifecycle paused this, rather than the
            | seller (§3.5). Re-publishing a product may then reactivate exactly
            | the offers it paused and leave alone the ones a seller paused for
            | their own reasons — a distinction that is unrecoverable without
            | storing it.
            */
            $table->boolean('paused_by_cascade')->default(false);

            $table->timestampTz('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->string('suspension_reason', 500)->nullable();

            $table->timestampsTz();
            // Withdrawal is a soft delete (§2.2): a future order line references
            // the offer it was bought from, so the row outlives the listing.
            $table->softDeletesTz();

            $table->index('selling_org_id');
            $table->index('store_uuid');
            $table->index('variant_uuid');
        });

        /*
        | ONE ACTIVE OFFER PER (org, variant) — §3.2, enforced by the database
        | rather than only by a check-then-insert, which races.
        |
        | Partial, on two conditions. `deleted_at IS NULL` alone would be enough
        | today because withdrawing soft-deletes; `status <> 'withdrawn'` is
        | stated as well so the guarantee does not quietly depend on those two
        | facts staying wired together. A withdrawn offer must NOT block listing
        | the variant again later, which is the whole reason this index is
        | partial instead of a plain unique.
        */
        DB::statement(
            'CREATE UNIQUE INDEX offers_one_live_per_org_variant '
            .'ON offers (selling_org_id, variant_uuid) '
            ."WHERE deleted_at IS NULL AND status <> 'withdrawn'",
        );

        /*
        | THE BUY BOX QUERY (§5, ADR-045). The winner is computed on every
        | product-page read — "cheapest Active, in-stock offer for this product,
        | ties by earliest created_at" — so this index is what makes choosing not
        | to store a winner cheap rather than merely principled.
        |
        | Partial on the eligibility predicate so the index holds only rows that
        | can actually win: a catalog with many paused or sold-out offers does not
        | pay for them here. Column order is the query's: equality on
        | product_uuid, then the two sort keys in order, so it satisfies the ORDER
        | BY without a sort step.
        */
        DB::statement(
            'CREATE INDEX offers_buy_box '
            .'ON offers (product_uuid, price_minor, created_at) '
            ."WHERE deleted_at IS NULL AND status = 'active' AND stock_quantity > 0",
        );

        // The same question asked for one SKU rather than a product — what a
        // future cart line resolves against (ADR-039).
        DB::statement(
            'CREATE INDEX offers_buy_box_variant '
            .'ON offers (variant_uuid, price_minor, created_at) '
            ."WHERE deleted_at IS NULL AND status = 'active' AND stock_quantity > 0",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
