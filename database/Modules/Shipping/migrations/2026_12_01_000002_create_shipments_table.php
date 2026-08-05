<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One parcel per paid order (ADR-063, Shipping.md §2).
 *
 * **ONE SHIPMENT PER ORDER, ENFORCED BY A UNIQUE INDEX** — and that index is not
 * bookkeeping, it is the idempotency of the whole module. The row is created by a
 * listener on the payment-succeeded event, which PayTR may deliver many times
 * (it retries until it hears "OK"); without the constraint a retried callback
 * would hand the seller a second parcel to ship for one order.
 *
 * A checkout group's N seller orders (ADR-052) become N shipments — each seller
 * ships their own. Multi-shipment (one order in two boxes) is deliberately absent
 * in v1.
 *
 * NO FOREIGN KEY TO `orders`, and that is the module boundary written into the
 * schema. Shipping imports no module, so the order and the seller are carried as
 * uuids and resolved through Core contracts — exactly as Payment carries a
 * checkout group and Offer carries a product. The one FK is to `cargo_companies`,
 * which is this module's own.
 *
 * NO MONEY. There is no price, no KDV and no commission column here and there
 * never will be while v1 ships free (ADR-063).
 *
 * @see App\Modules\Shipping\Domain\Models\Shipment
 * @see docs/modules/Shipping.md §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | UNIQUE — see the class docblock. One order, one parcel, however many
            | times the payment event arrives.
            */
            $table->uuid('order_uuid')->unique();

            // Who has to put it in a box. A uuid, not a relation: Shipping may not
            // import Organization (ADR-040's id/uuid discipline, uuid half only —
            // nothing here needs to scope by internal id).
            $table->uuid('seller_org_uuid')->index();

            // Denormalised for the panels, so a shipment list does not have to ask
            // Order for a label per row. It is a SNAPSHOT of an immutable value —
            // an order number never changes (ADR-053) — so it cannot drift.
            $table->string('order_number', 32)->index();

            $table->string('status', 20)->default('pending')->index();

            /*
            | RESTRICTING, not cascading: a carrier that is retired must not take
            | the shipment history with it. Withdrawal is `is_active = false`.
            */
            $table->foreignId('cargo_company_id')->nullable()->constrained('cargo_companies')->restrictOnDelete();

            // As the seller typed it. Not validated against a carrier's format:
            // eight carriers with eight formats is a rule that would reject a
            // legitimate number the week a carrier changes it.
            $table->string('tracking_number', 100)->nullable();

            $table->timestampTz('shipped_at')->nullable();

            /*
            | THE DATE THE REST OF THE PLATFORM WAITS ON (ADR-064). Payout and the
            | return window both key off it, whichever way it was set — which is
            | why `delivered_via` sits beside it: an inferred date and an observed
            | one are worth different amounts in a dispute, and a single timestamp
            | could not say which it was.
            */
            $table->timestampTz('delivered_at')->nullable();
            $table->string('delivered_via', 20)->nullable();

            $table->timestampTz('returned_at')->nullable();

            $table->timestampsTz();

            // "What do I still have to ship?" — the seller panel's only real
            // query, and the transit sweep's (S2) is `status + shipped_at`.
            $table->index(['seller_org_uuid', 'status']);
            $table->index(['status', 'shipped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
