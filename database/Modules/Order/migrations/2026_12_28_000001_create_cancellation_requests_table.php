<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer asks; the seller answers (ADR-065, C2).
 *
 * **NO MONEY COLUMN, AND THAT IS WHY THE TABLE IS ORDER'S.** It records that
 * somebody asked to cancel and what came of it. The refund, the commission
 * reversal and the restock happen behind the Core cancellation port, in Payment,
 * and nothing about them is duplicated here — an amount on this row would be a
 * second version of a number the ledger already holds.
 *
 * **THE PARTIAL UNIQUE INDEX IS THE "ONE OPEN REQUEST" RULE**, and it is keyed on
 * `pending` alone: a rejected request must not block asking again, because
 * circumstances change and a seller who said no on Monday may say yes on Thursday
 * while the item still has not shipped. An unconditional unique on `order_uuid`
 * would have made a single refusal permanent.
 *
 * **PostgreSQL ONLY, exactly as `customer_addresses`' default-address indexes
 * are** (2026-10-01), and for the same reason: the suite runs on SQLite, where
 * this statement is not portable enough to rely on. So the guarantee is stated
 * twice — the action checks for an open request inside its transaction, and
 * production keeps the database backstop for the double-click the check cannot
 * see. A test asserts the ACTION's half, which is the half that runs everywhere.
 *
 * NO FOREIGN KEY TO `orders`, even though it is the same module's table. The
 * order is addressed by uuid on every surface this row touches, and carrying an
 * id beside it would invite two answers to "which order is this".
 *
 * @see App\Modules\Order\Domain\Models\CancellationRequest
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('order_uuid')->index();

            // The customer who asked, as a `users` id: it is compared against the
            // authenticated actor and never leaves the application
            // (non-negotiable #7).
            $table->unsignedBigInteger('requested_by');

            // Why they want out. Optional — "vazgeçtim" is a complete answer, and
            // demanding a paragraph would only produce fake ones.
            $table->text('reason')->nullable();

            $table->string('status', 20)->index();

            // The seller's side of the exchange. `decision_reason` is what a
            // REJECTED buyer is shown — refusing without a word is the support
            // ticket this column exists to prevent.
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX cancellation_requests_one_open
                 ON cancellation_requests (order_uuid)
                 WHERE status = \'pending\'',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_requests');
    }
};
