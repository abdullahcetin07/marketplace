<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One payment per checkout group — the buyer pays once for the whole basket
 * (ADR-060, Payment.md §4).
 *
 * THE MIRROR OF ADR-052'S SPLIT. Order split one basket into N orders so N
 * sellers could each fulfil their part; Payment rejoins them because a card is
 * charged once. So the key here is the `checkout_group_uuid`, not an order.
 *
 * NO FOREIGN KEY TO ANYTHING, and that is the module boundary in the schema.
 * Payment imports no module, so it cannot reference `orders.id` — the group is
 * carried as a uuid and resolved through the Core read port, exactly as Offer
 * carries `product_uuid` and Order carries `offer_uuid`.
 *
 * NO CARD DATA. There is no column here that could hold a PAN, an expiry or a
 * CVV, and there never will be: the buyer's card lives inside the PSP's iframe
 * (Payment.md §3). What is stored is a reference and an outcome.
 *
 * @see App\Modules\Payment\Domain\Models\Payment
 * @see docs/modules/Payment.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | UNIQUE, because one basket is charged once. It is also what makes a
            | double-`initiate` idempotent rather than a second charge: the second
            | call finds this row instead of creating one. A duplicate here would
            | mean two live PSP sessions for one basket, and whichever the buyer
            | happened to complete would leave the other holding stock forever.
            */
            $table->uuid('checkout_group_uuid')->unique();

            // The paying customer, by the id/uuid pair (ADR-040): the id scopes the
            // owner-only status read, the uuid is what may leave the application.
            $table->unsignedBigInteger('customer_id')->index();
            $table->uuid('customer_uuid')->index();

            /*
            | KURUŞ. An integer, like every amount on this platform (ADR-005) — and
            | PayTR's own unit is the same, so nothing is converted anywhere in the
            | chain. A DECIMAL here would be the financial bug the whole rule
            | exists to prevent.
            */
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            $table->string('status', 24)->default('pending')->index();

            /*
            | THE PSP'S SIDE OF THE STORY, kept verbatim for support and disputes.
            | `provider_reference` is PayTR's own id for the transaction;
            | `failure_reason` is whatever it said when it refused, unparsed —
            | a normalised failure code would lose the one string a support agent
            | can quote back to the provider.
            */
            $table->string('provider', 20)->default('paytr');
            $table->string('provider_reference')->nullable();
            $table->text('failure_reason')->nullable();

            /*
            | The raw verified callback, for the audit trail. JSONB rather than a
            | set of columns because it is EVIDENCE, not data the application
            | queries: its shape is the provider's to change.
            */
            $table->jsonb('provider_payload')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestampsTz();

            // "Has this customer paid for anything recently" — the account page's
            // read, and the only non-key access pattern P1 has.
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
