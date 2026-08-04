<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One order's money, given back (Payment.md §8, P5).
 *
 * ONE ROW PER (PAYMENT, ORDER), NOT PER REFUND REQUEST, and the unique index is
 * the whole reason this table exists rather than a pair of columns on `payments`.
 * A refund is the one operation in this module that a human triggers by clicking
 * something, which means it WILL be clicked twice — and unlike the callback, there
 * is no PSP retry semantics to lean on. The database refusing the second row is
 * what stops a seller being debited twice for one returned parcel.
 *
 * WHY PER ORDER AND NOT PER PAYMENT. A payment is one basket and N sellers'
 * orders (ADR-052); refunding one seller's order while the others stand is the
 * ordinary case, not the exotic one — a "partial refund" on this platform means
 * exactly that. Keying on the order is what makes the partial case the same code
 * as the full one.
 *
 * WHAT IS REFUNDED IS A SUM OF THESE ROWS, never a column on `payments`. Same
 * rule as the seller balance (ADR-062): a total that is stored can disagree with
 * the rows it summarises, and the day it does there is no way to tell which is
 * right.
 *
 * APPEND-ONLY. `created_at` alone — there is no update a refund could receive. If
 * the PSP later reverses it, that is another fact, not an edit to this one.
 *
 * NO FOREIGN KEY TO `orders`. Payment imports no module and its schema says the
 * same thing: the order is a uuid, resolved through the Core read port. The one
 * FK here is to `payments`, which is this module's own.
 *
 * @see App\Modules\Payment\Domain\Models\PaymentRefund
 * @see docs/modules/Payment.md §8
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->uuid('payment_uuid')->index();

            // The refunded order and the seller it belongs to, by uuid — the
            // module boundary in the schema (ADR-040).
            $table->uuid('order_uuid');
            $table->uuid('seller_org_uuid')->index();

            // KURUŞ, like every amount on this platform (ADR-005).
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // The PSP's own id for the reversal — what a support agent quotes back
            // to PayTR when the buyer says the money never arrived.
            $table->string('provider_reference')->nullable();
            $table->text('reason')->nullable();

            // WHO DECIDED. A `users` id, which is permitted because `app/Models`
            // sits above the modules (001 §6). Nullable for a refund the system
            // itself makes, which nothing does yet.
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->timestampTz('created_at')->nullable();

            /*
            | THE GUARD, IN THE SCHEMA. One order is refunded once; a double-click,
            | a retried request or two admins acting at the same moment all end at
            | this index rather than at a second ledger debit.
            */
            $table->unique(['payment_id', 'order_uuid']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            // The state machine's third timestamp, beside `paid_at`/`failed_at`.
            // The AMOUNT refunded is deliberately not here — it is Σ of the rows
            // above.
            $table->timestampTz('refunded_at')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('refunded_at');
        });

        Schema::dropIfExists('payment_refunds');
    }
};
