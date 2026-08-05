<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a delivery ALLOWS — the two clocks it starts (ADR-064, Shipping.md §4).
 *
 * SHIPPING OWNS THE DELIVERY; PAYMENT OWNS WHAT IT PERMITS. A parcel arriving is
 * a fact about the physical world and lives on `shipments`. These are the two
 * consequences the money side draws from it, and they are stored rather than
 * recomputed because both are read constantly — every payout screen asks "what may
 * I send", every return asks "is it too late" — and neither answer may change
 * because an operator edited a setting last week.
 *
 * **THE WINDOWS ARE FROZEN AT DELIVERY, WHICH IS THE POINT OF THIS TABLE.**
 * `settings('shipping.payout_hold_days')` is operator-tunable, and if the dates
 * were derived on read then shortening the hold would retroactively make last
 * month's undelivered-yesterday orders payable, and lengthening it would withdraw
 * a payout a seller had already been promised. Freezing them is the same
 * discipline as an order line's price (ADR-053): the rule that applied when the
 * event happened is the rule that governs it.
 *
 * ONE ROW PER ORDER, UNIQUE. A shipment is delivered once (Shipping's own guard
 * refuses a second), and this index is the second line of that defence: a replayed
 * event cannot push a payout date out by however long the replay took.
 *
 * NO FOREIGN KEY TO `orders` OR `shipments` — the module boundary in the schema.
 * Payment imports no module; the order, the seller and the shipment are uuids.
 *
 * @see App\Modules\Payment\Domain\Models\SettlementWindow
 * @see docs/modules/Payment.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_windows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // UNIQUE: one delivery per order, one set of windows.
            $table->uuid('order_uuid')->unique();
            $table->uuid('seller_org_uuid')->index();

            /*
            | THE DATE EVERYTHING HERE IS MEASURED FROM, copied from the event
            | rather than read off the clock at consume time — a queued listener
            | running an hour late must not push a seller's payday an hour out.
            */
            $table->timestampTz('delivered_at');

            /*
            | HOW THE DELIVERY WAS ESTABLISHED, carried across the boundary as a
            | string. A payout released on a BUYER-CONFIRMED delivery and one
            | released because a clock ran out are the same money and a different
            | amount of confidence, and the finance side should be able to tell
            | them apart without asking Shipping.
            */
            $table->string('delivered_via', 20);

            /*
            | `delivered_at + payout_hold_days`. The seller's money is not payable
            | before this — they must not be paid for goods the buyer can still
            | send back, or the platform is recovering money it already handed
            | over.
            */
            $table->timestampTz('payout_eligible_at')->index();

            // `delivered_at + return_days`. S4's guard.
            $table->timestampTz('return_window_ends_at')->index();

            $table->timestampsTz();

            // "What may I pay this seller today" — the payout screen's only read.
            $table->index(['seller_org_uuid', 'payout_eligible_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_windows');
    }
};
