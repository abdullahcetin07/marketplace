<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's pipeline: basket, address book, orders and their frozen lines
 * (ADR-052…056).
 *
 * FIVE TABLES, AND THE SPLIT BETWEEN THEM IS THE ARCHITECTURE:
 *
 *   carts / cart_items       mutable scratch space, NO PRICES
 *   customer_addresses       a book the customer edits freely
 *   orders / order_lines     a financial record, written once
 *
 * The pair on each side of that line is deliberate. A basket must follow a
 * seller's price change; an order must never move again (ADR-053). An address a
 * customer edits must not rewrite where last year's parcel went — which is why
 * an order carries JSON SNAPSHOTS of both addresses instead of foreign keys into
 * the book.
 *
 * NOT ONE FOREIGN KEY LEAVES THIS MODULE. No `customer_id` constraint into
 * `users`, no `offer_id` into `offers`, no `variant_id` into `product_variants`.
 * Order imports no module (ADR-052…056) and references every other context by
 * the ADR-040 id/uuid pair or a bare uuid; a database-level FK would be that
 * import wearing a different hat — and would make an order undeletable-by-cascade
 * from a module that has no business deciding an order's lifetime.
 *
 * The cost is real and accepted: nothing at the storage layer stops an order line
 * naming an offer uuid that does not exist. The Application layer validates every
 * reference against the Core contracts before writing, and that is where the
 * check belongs — the same trade Offer and Inventory already made.
 *
 * MONEY IS `bigInteger` MINOR UNITS (non-negotiable #6). Never DECIMAL, never a
 * float. `tax_rate` IS DECIMAL because a rate is a ratio, not an amount
 * (ADR-005).
 *
 * @see docs/modules/Order.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        |----------------------------------------------------------------------
        | The basket
        |----------------------------------------------------------------------
        */
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | ONE ACTIVE CART PER CUSTOMER, enforced here rather than trusted to
            | the action: two carts for one shopper is a state with no correct
            | resolution — whichever one checkout picked, the other's contents
            | vanish silently.
            |
            | The ADR-040 pair: the id is the tenancy filter on every request, the
            | uuid is what leaves the application. No FK into `users` — see the
            | class docblock.
            */
            $table->unsignedBigInteger('customer_id')->unique();
            $table->uuid('customer_uuid')->index();

            $table->timestampsTz();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Cascade: a line has no meaning apart from its basket (ADR-014 —
            // dependent child records). The one FK in this file, and it is
            // module-internal.
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();

            $table->uuid('offer_uuid');

            /*
            | DENORMALIZED IDENTITY, not value (§2.1). `selling_org_uuid` is what
            | checkout partitions on (ADR-052) and `variant_uuid` is what
            | Inventory reserves against — reading either through a contract per
            | line would mean N queries on every cart render. Uuids do not change,
            | so unlike a price these cannot go stale.
            */
            $table->uuid('variant_uuid');
            $table->uuid('product_uuid');
            $table->uuid('selling_org_uuid');
            $table->uuid('store_uuid');

            // THERE IS NO PRICE COLUMN IN THIS TABLE, by design. What it costs is
            // read live from the Offer and snapshotted only at placement
            // (ADR-053). If you are here to add one, you want `order_lines`.
            $table->unsignedInteger('quantity');

            $table->timestampsTz();

            /*
            | One line per offer. Adding the same offer twice raises the quantity
            | instead — two lines for one thing is a basket a customer cannot
            | reason about, and a checkout that would reserve twice against the
            | same (org, variant).
            */
            $table->unique(['cart_id', 'offer_uuid']);

            // The split: "which sellers is this basket from", answered without a
            // scan.
            $table->index(['cart_id', 'selling_org_uuid']);
        });

        /*
        |----------------------------------------------------------------------
        | The address book (ADR-056)
        |----------------------------------------------------------------------
        */
        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('customer_id');
            $table->uuid('customer_uuid')->index();

            // The customer's own name for it ("Ev", "Ofis") — what makes a picker
            // usable, and half the reason a book beats a single address field.
            $table->string('label', 60);

            $table->string('recipient_name');
            $table->string('phone', 32);

            $table->string('line1');
            $table->string('line2')->nullable();
            // The Turkish shape: ilçe beside il. Plain strings, because validating
            // world addresses structurally is a project of its own and getting it
            // half right rejects real addresses.
            $table->string('district')->nullable();
            $table->string('city');
            $table->string('postal_code', 20)->nullable();

            // Localization is the platform-wide reference-data exception every
            // module may read (§5.1), so this FK is permitted where none of the
            // others are. RESTRICT: a country with addresses on it is deactivated,
            // never deleted.
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();

            /*
            | TWO DEFAULTS, NOT ONE. "Deliver to the office, invoice the company"
            | is ordinary rather than exotic, and one flag would force a customer
            | to retype an address on every order.
            |
            | Uniqueness is NOT enforced here. A partial unique index (one default
            | per customer per purpose) is expressible on pgsql and not on sqlite,
            | and the action already clears the previous default inside its
            | transaction. Enforcing it in one engine only would mean the suite
            | could never exercise the constraint that production relies on.
            */
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);

            $table->timestampsTz();
            // Soft: deleting an address cannot orphan an order — the order already
            // holds its own snapshot — but a customer may still be looking at a
            // list that references it.
            $table->softDeletesTz();

            $table->index(['customer_id', 'deleted_at']);
        });

        /*
        |----------------------------------------------------------------------
        | The orders (ADR-052/053)
        |----------------------------------------------------------------------
        */
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The human handle — quoted in an email, read down the phone. UNIQUE
            // because a support agent searching one must get exactly one order.
            $table->string('order_number', 32)->unique();

            /*
            | THE GRAIN OF THIS TABLE (ADR-052): one row per SELLER per checkout,
            | grouped by this uuid. Indexed rather than unique — that is the whole
            | point, N rows share it — and it is the first thing a customer-facing
            | "my purchase" screen and a future Payment both look up by.
            */
            $table->uuid('checkout_group_uuid')->index();

            $table->unsignedBigInteger('customer_id');
            $table->uuid('customer_uuid')->index();

            // The seller. A bare uuid, not the id/uuid pair: unlike the catalog's
            // seller panel, the order panels scope by uuid because
            // `OrganizationAuthorizationContract` can translate ids to the uuids
            // this column holds, and no query here filters by internal id.
            $table->uuid('selling_org_uuid');
            $table->uuid('store_uuid');

            $table->string('status', 20)->index();

            // Money, in minor units, three columns rather than one derived pair:
            // an invoice needs the breakdown that was AGREED, not one recomputed
            // later from a rate that may since have changed (§3.4).
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->bigInteger('items_total_minor')->default(0);
            $table->bigInteger('tax_total_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);

            /*
            | SNAPSHOTS, NOT FOREIGN KEYS (ADR-053/056). JSON because the shape
            | belongs to the order and must survive the address book — and the
            | `countries` table — changing under it. An FK here would let a
            | customer moving house rewrite where a delivered parcel went.
            */
            $table->json('shipping_address');
            $table->json('billing_address');

            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestampsTz();

            // NO SOFT DELETES. An order is a financial record; there is no
            // business action that removes one, and offering the column would
            // invite one. Cancelled is the end state.

            // The seller panel: my orders, newest first.
            $table->index(['selling_org_uuid', 'status']);
            // The customer's order history.
            $table->index(['customer_id', 'created_at']);
            /*
            | THE EXPIRY SWEEP (§3.3): pending orders older than the window. A
            | composite on (status, created_at) so the job reads an index range
            | rather than scanning every order the platform has ever taken —
            | it runs on a schedule forever, against a table that only grows.
            */
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Cascade at the schema level for integrity; the model refuses
            // deletes outright (ADR-053), so this can only ever fire if an order
            // itself were deleted, which nothing does.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // What was bought, by reference — for a future returns or dispute
            // flow to trace back. No FKs: see the class docblock.
            $table->uuid('offer_uuid');
            $table->uuid('variant_uuid');
            $table->uuid('product_uuid');

            /*
            | THE SNAPSHOT (ADR-053). These are not a cache of the catalog — they
            | are the record of what the customer was shown and agreed to. The
            | product may be re-titled or withdrawn entirely; this row does not
            | move.
            */
            $table->string('product_title');
            $table->string('variant_label')->nullable();

            // KDV-INCLUDED (ADR-042). Minor units, never a float.
            $table->bigInteger('unit_price_minor');
            /*
            | The rate that produced `line_tax_minor`, frozen beside it. DECIMAL
            | because a rate is a ratio (ADR-005), and stored rather than looked
            | up at print time because a bracket revised next year must not change
            | what an old invoice says.
            */
            $table->decimal('tax_rate', 6, 4);
            $table->unsignedInteger('quantity');
            $table->bigInteger('line_tax_minor');
            $table->bigInteger('line_total_minor');

            // Created only. There is no `updated_at` because there is no update —
            // the same reasoning as an audit entry and a stock movement (ADR-050).
            $table->timestampTz('created_at')->nullable();

            // "What did we sell of this variant" — the question a seller's
            // reporting and a future returns flow both start from.
            $table->index('variant_uuid');
            $table->index('offer_uuid');
        });

        /*
        | ONE DEFAULT ADDRESS PER PURPOSE, on PostgreSQL.
        |
        | A PARTIAL unique index: `WHERE is_default_shipping` indexes only the
        | rows that claim to be the default, so a customer may hold many
        | non-default addresses and at most one default of each kind. SQLite
        | cannot express a partial unique index over a boolean this way, so the
        | suite exercises the ACTION's guarantee instead — it clears the previous
        | default inside the same transaction — and production keeps the backstop.
        |
        | Deliberately NOT worked around by making the column nullable-unique: a
        | nullable "true or null" flag reads as a bug to everyone who meets it.
        */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX customer_addresses_one_default_shipping
                 ON customer_addresses (customer_id)
                 WHERE is_default_shipping AND deleted_at IS NULL'
            );

            DB::statement(
                'CREATE UNIQUE INDEX customer_addresses_one_default_billing
                 ON customer_addresses (customer_id)
                 WHERE is_default_billing AND deleted_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
