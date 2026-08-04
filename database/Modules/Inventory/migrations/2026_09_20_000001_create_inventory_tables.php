<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The availability authority's three tables (ADR-048–051).
 *
 * NO FOREIGN KEYS OUT OF THE MODULE. The variant, the product, the offer and the
 * selling organization are all uuids — plus the org's internal id, which the
 * tenancy filter needs (ADR-040). A FK across a bounded-context boundary would
 * mean a join Inventory is not allowed to make and, worse, would invite the
 * relation the models deliberately refuse. The two FKs here are internal, from
 * the ledger and the reservations to their own pool.
 *
 * NO MONEY ANYWHERE. Stock is counts, so the integer-minor-units rule simply
 * does not apply (Inventory.md §8) — these are plain unsigned integers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The catalog references — uuid only, no FK (ADR-040).
            $table->uuid('variant_uuid');
            // Denormalized from the variant, as the Offer does: a seller's stock
            // list groups by product, and resolving it through the Catalog on
            // every read would put a cross-context call inside a hot query.
            $table->uuid('product_uuid');
            // Provenance: which offer's stock this mirrors. Nullable because a
            // withdrawn offer leaves the pool behind — the ledger outlives the
            // listing, and zeroing on-hand is not the same as forgetting.
            $table->uuid('offer_uuid')->nullable();

            /*
            | The selling company. The internal id is what the seller panel's
            | tenancy wall filters on (`organizationIdsForUser()` speaks internal
            | ids); the uuid is what every payload and event carries. Both,
            | because neither alone does both jobs (ADR-040).
            */
            $table->unsignedBigInteger('selling_org_id');
            $table->uuid('selling_org_uuid');

            /*
            | PROJECTIONS of the movement ledger (ADR-050), written in the same
            | transaction as the movement that changes them.
            |
            | UNSIGNED, so "negative stock" is impossible at the storage layer
            | and not merely in validation — the invariant that matters most
            | here is enforced by the column type, not by a code path somebody
            | could route around.
            |
            | `available` is deliberately ABSENT: it is on_hand − reserved,
            | computed on read and never stored.
            */
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);

            // Nullable means "the seller never asked to be warned" — distinct
            // from 0, which means "tell me when I actually run out" (§3.3).
            $table->unsignedInteger('low_stock_threshold')->nullable();
            // Edge-triggering: set when the warning fires, cleared when
            // availability climbs back above the line, so a pool sitting low
            // does not notify on every movement.
            $table->boolean('low_stock_notified')->default(false);

            $table->timestampsTz();

            /*
            | ONE POOL PER (org, variant) — ADR-051, enforced by the database
            | rather than by a check-then-insert, which races. Two Offer events
            | for the same seller and variant arriving together must produce one
            | pool, not two that each hold half the truth.
            */
            $table->unique(['selling_org_id', 'variant_uuid'], 'stock_items_one_pool_per_org_variant');

            // The buy box's read: "what can THIS seller sell of THIS variant".
            $table->index(['variant_uuid', 'selling_org_uuid'], 'stock_items_availability_lookup');
            // The seller panel's listing.
            $table->index('selling_org_id');
            $table->index('product_uuid');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();

            $table->string('type', 32)->index();

            /*
            | SIGNED deltas — a movement says what CHANGED. Signed, so the
            | ledger sums to the projection; `integer` rather than `unsigned`
            | precisely because a commit and a release move numbers downward.
            */
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);

            // The CALLER's key (a reservation uuid today, an order uuid later).
            // Indexed because idempotency asks "have I already recorded this?".
            $table->string('reference')->nullable()->index();
            $table->string('note', 500)->nullable();

            /*
            | Append-only (non-negotiable #9, ADR-050), so there is no
            | `updated_at` — nothing ever updates a row, and the column would be
            | dead weight on the busiest table this module has. The model
            | refuses updates and deletes outright.
            */
            $table->timestampTz('created_at')->nullable();

            // The seller's movement history: newest first, per pool.
            $table->index(['stock_item_id', 'id'], 'stock_movements_history');
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | THE CALLER'S OWN KEY, and the idempotency guarantee. Unique, so a
            | retried checkout finds its existing hold instead of taking a
            | second one — the difference between a webhook that fires twice
            | being harmless and it overselling.
            */
            $table->uuid('reference_uuid')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->string('status', 20)->index();

            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->timestampsTz();

            // "What is still held against this pool" — the sum reservations
            // that `reserved` is a projection of.
            $table->index(['stock_item_id', 'status'], 'stock_reservations_active_per_item');
        });

        /*
        | THE INVARIANT ADR-048 RESTS ON, at the storage layer.
        |
        | `reserved` may never exceed `on_hand`. The unsigned columns already
        | stop either going negative on BOTH drivers; this stops the pair going
        | incoherent, which is what a reservation bug looks like from outside and
        | which no amount of application care guarantees under concurrency the
        | way a constraint does.
        |
        | POSTGRES ONLY, and not by preference. SQLite accepts CHECK only inside
        | CREATE TABLE, and Laravel's Blueprint cannot express one there — an
        | ALTER is a syntax error. Production is Postgres, so the guarantee is
        | real where it matters; the suite runs on SQLite, so the tests carry it
        | instead, which is why `ReserveStockAction`'s refusal and the concurrent-
        | reserve race are both asserted rather than left to the database.
        */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE stock_items ADD CONSTRAINT stock_items_reserved_within_on_hand '
                .'CHECK (reserved <= on_hand)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
    }
};
