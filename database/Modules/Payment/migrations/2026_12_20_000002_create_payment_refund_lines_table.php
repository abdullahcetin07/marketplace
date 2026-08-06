<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which lines came back, and how many of each (S4).
 *
 * **P5'S UNIQUE INDEX HAS TO GO, AND THAT IS THE RISKY PART OF THIS MIGRATION.**
 * `payment_refunds` was UNIQUE on `(payment_id, order_uuid)` because a refund was
 * whole-order: one refund per order, and a second click met the database. A
 * line-level refund means an order can legitimately be refunded MORE THAN ONCE —
 * one of the two shoes today, the other next week — so the index cannot stand.
 *
 * **THE GUARANTEE MOVES RATHER THAN DISAPPEARS.** What P5 was protecting was "you
 * cannot refund the same thing twice", and that becomes arithmetic: a line may be
 * refunded up to its REMAINING quantity, summed from the rows below. A double
 * click now meets a quantity check instead of a constraint. That is a weaker
 * mechanism honestly stated — a unique index cannot be forgotten and a sum can —
 * which is why the check lives in one place (`RefundableLines`) and is tested
 * against a concurrent second call.
 *
 * ONE ROW PER (REFUND, ORDER LINE). A refund of two different lines in one
 * request is one `payment_refunds` row and two of these, which is what makes "how
 * much of this line have I already sent back" a single SUM.
 *
 * NO FOREIGN KEY TO `order_lines` — the module boundary in the schema. Payment
 * imports no module; the line is a uuid read through the Core port.
 *
 * @see App\Modules\Payment\Domain\Models\PaymentRefundLine
 * @see App\Modules\Payment\Domain\Support\RefundableLines
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refund_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_refund_id')->constrained('payment_refunds')->cascadeOnDelete();

            // The order line, by uuid — a public identifier crossing a module
            // boundary (ADR-040).
            $table->uuid('order_line_uuid')->index();
            // Carried so a restock does not need a second read: the reservation
            // key is `{order_uuid}:{variant_uuid}` (ADR-049).
            $table->uuid('variant_uuid');

            $table->unsignedInteger('quantity');

            /*
            | WHAT THIS MANY UNITS CAME TO, in integer kuruş (ADR-005) — the
            | KDV-INCLUSIVE amount actually sent back to the buyer, and the
            | proportional share of the commission given back to the seller. Both
            | are STORED rather than recomputed: the rate that produced them was
            | frozen at payment (ADR-061) and a rounding rule changed next year
            | must not silently restate what was refunded last year.
            */
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('commission_minor')->default(0);

            $table->timestampTz('created_at')->nullable();
        });

        Schema::table('payment_refunds', function (Blueprint $table): void {
            // @see the class docblock. The guarantee moves to the quantity check.
            $table->dropUnique(['payment_id', 'order_uuid']);
            $table->index(['payment_id', 'order_uuid']);
        });

    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropIndex(['payment_id', 'order_uuid']);
        });

        Schema::dropIfExists('payment_refund_lines');

        /*
        | THE UNIQUE INDEX IS NOT RESTORED, and that is deliberate rather than
        | lazy: by the time anyone rolls this back there may be two refunds for one
        | order, which is exactly what this migration made legal. Re-imposing it
        | would fail on real data, and choosing which refund to delete is a
        | decision a human makes before rolling back, not one a `down()` makes for
        | them.
        */
    }
};
