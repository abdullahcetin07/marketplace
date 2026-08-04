<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each seller is owed — as a LEDGER, never a balance column (ADR-062,
 * Payment.md §7).
 *
 * THE DECISION IS THE ABSENCE OF A COLUMN. There is no `sellers.balance` anywhere,
 * and there must not be: a stored balance is a number that can drift from the
 * events that produced it, and the first time it does, nobody can tell which is
 * right. Balance is `Σ amount_minor`, computed on read — the same append-only
 * discipline as Audit and Inventory's movement ledger, applied to money, where it
 * matters most.
 *
 * SIGNED AMOUNTS, SO THE SUM IS THE ANSWER. A `sale_credit` is positive and a
 * `commission_debit` is negative, so a seller's balance is a `SUM()` and not a
 * `CASE` ladder that has to know what every type means. The sign is decided once,
 * by `LedgerEntryType::signedAmount()`, so no call site can append a positive
 * commission and pay the seller the platform's cut.
 *
 * TWO ENTRIES PER PAID ORDER, NOT ONE NET FIGURE. The seller's balance rises by
 * the sale and falls by the commission as separate rows, because "you earned
 * 129,90 and we took 23,38" is a sentence a seller can check and "you earned
 * 106,52" is one they can only accept.
 *
 * THE IDEMPOTENCY INDEX IS WHAT MAKES A RETRY SAFE. PayTR re-sends a callback
 * until it hears "OK", so the same payment may credit the same seller more than
 * once; `(payment_uuid, order_uuid, type)` is UNIQUE, which turns a double credit
 * from a silent accounting error into a refused insert. A payout entry has no
 * order, and a nullable column in a unique index is not deduplicated by
 * PostgreSQL — so P4's payouts get their own guard rather than relying on this
 * one.
 *
 * NO FOREIGN KEYS. Payment imports no module: the seller, the order and the
 * payment are all uuids. The cost is that nothing enforces they exist; the benefit
 * is that a ledger row survives every one of them being reorganised, which for a
 * financial record is the right way round.
 *
 * NO `updated_at`. Nothing updates a row once written, so the column would be dead
 * weight — the same reasoning `StockMovement` uses.
 *
 * @see App\Modules\Payment\Domain\Models\SellerLedgerEntry
 * @see docs/modules/Payment.md §7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('seller_org_uuid');

            $table->string('type', 32);

            /*
            | SIGNED KURUŞ. Integer, like every amount on this platform (ADR-005),
            | and signed so the balance is a plain SUM. `bigInteger` rather than
            | `unsignedBigInteger` precisely because half these rows are negative —
            | which is the whole mechanism.
            */
            $table->bigInteger('amount_minor');

            // What produced this row. Nullable individually because a sale entry
            // has no payout and a payout entry has no order.
            $table->uuid('order_uuid')->nullable();
            $table->uuid('payment_uuid')->nullable();
            $table->uuid('payout_uuid')->nullable();

            // A human's note, for the entries a human creates (P4's payouts).
            $table->string('note')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            /*
            | THE BALANCE READ: "every entry for this seller". Covering the sum
            | column so the aggregate can be answered from the index alone — this
            | is the query a seller's dashboard, every payout guard and every
            | reconciliation runs.
            */
            $table->index(['seller_org_uuid', 'created_at']);

            // Retry protection — see the class docblock.
            $table->unique(['payment_uuid', 'order_uuid', 'type'], 'seller_ledger_settlement_unique');

            // "What did this order produce?" — the reconciliation direction.
            $table->index('order_uuid');
            $table->index('payout_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_ledger_entries');
    }
};
