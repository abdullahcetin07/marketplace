<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The points ledger (ADR-081).
 *
 * **APPEND-ONLY, AND THE BALANCE IS A SUM.** There is no `balance` column here or
 * anywhere else: a stored total is a second source of truth that drifts silently,
 * and the failure — a customer's points quietly wrong — is discovered by the
 * customer. `SUM(points)` is the balance, computed on read, and every row that
 * produced it is still there to explain it.
 *
 * **THE UNIQUE KEY IS THE IDEMPOTENCY GUARANTEE.** `(source_type, source_uuid)`:
 * signup keys on the customer, a review on the review, a purchase on the
 * seller-order. A re-emitted event, a re-run sweep, a listener that fires twice —
 * none of them can credit twice, and the guard is the database rather than a
 * check somebody has to remember.
 *
 * **`points` IS A COUNT, NOT MONEY.** No currency, no minor units: ADR-005 governs
 * money and a point is not money until `loyalty.redeem.value` turns it into some.
 * It is SIGNED because Phase 2 spends them (ADR-084) and a spend is a negative row
 * rather than a deletion — which is the same reason the table refuses updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // THE CUSTOMER BY UUID, not by id: Loyalty imports no module and holds
            // no foreign key into somebody else's table (ADR-082).
            $table->uuid('customer_uuid')->index();

            $table->integer('points');

            $table->string('source_type', 32);
            /*
            | **A SOURCE IDENTIFIER, NOT A UUID** — widened in a later migration
            | after PostgreSQL refused a reversal key (ADR-084). It is a customer
            | uuid, a review uuid, an order uuid, or `"{group}:{cause}"` for a
            | refund, which has to vary per refund so a basket partly cancelled and
            | later partly returned credits twice rather than once.
            */
            $table->string('source_uuid', 191);

            // The rate that produced the row, for the audit trail. A rate change is
            // never retroactive, so the only way to explain an old row is to have
            // kept what it was computed with.
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source_type', 'source_uuid'], 'loyalty_ledger_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
    }
};
