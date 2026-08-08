<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer asks to send it back; the seller approves, then confirms it arrived
 * (ADR-073).
 *
 * **NO MONEY COLUMN, AND THAT IS WHY THE TABLE IS ORDER'S** — the same rule
 * `cancellation_requests` follows. It records that somebody asked to return
 * something and what came of it; the refund, the proportional commission reversal
 * and the restock all happen behind the Core return port, in Payment.
 *
 * **`line_quantities` IS A JSON PAYLOAD RATHER THAN A CHILD TABLE**, deliberately.
 * These quantities are never queried, aggregated or joined — they are handed
 * whole to Payment's port, which re-checks each one against
 * `payment_refund_lines` before a kuruş moves. A `return_request_lines` table
 * would be a second place quantities live, and it would be the non-authoritative
 * one.
 *
 * **THE PARTIAL UNIQUE INDEX COUNTS TWO STATES, NOT ONE.** A cancellation is over
 * the moment the seller answers, so its index keys on `pending`. A return is not:
 * an APPROVED return is a buyer walking to the cargo desk, and a second request
 * for the same order while that is in flight is a mistake, not a new intention.
 * A rejected or completed one does not block asking again — a second shoe next
 * week is a legitimate second return (Payment.md §8's S4 note).
 *
 * **PostgreSQL ONLY, exactly as `cancellation_requests_one_open` is**, and for the
 * same reason: the suite runs on SQLite, where this statement is not portable
 * enough to rely on. The guarantee is therefore stated twice — the action checks
 * inside its transaction (tested everywhere) and production keeps the database
 * backstop for the double-click the check cannot see (tested on the real engine).
 *
 * NO FOREIGN KEY TO `orders`, even though it is the same module's table — the
 * order is addressed by uuid on every surface this row touches.
 *
 * @see App\Modules\Order\Domain\Models\ReturnRequest
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('order_uuid')->index();

            // The customer who asked, as a `users` id: compared against the
            // authenticated actor, never leaving the application
            // (non-negotiable #7).
            $table->unsignedBigInteger('requested_by');

            // The owning customer, carried separately because ownership scoping
            // is a query and `requested_by` is a record of who pressed the
            // button. Today they are the same person; a future household or
            // corporate account makes them different, and the scope must follow
            // the ACCOUNT.
            $table->unsignedBigInteger('customer_id');

            // Why it is coming back. Optional — "beğenmedim" is a complete answer
            // under Turkish distance-selling rules, and demanding a paragraph
            // would only produce fake ones.
            $table->text('reason')->nullable();

            $table->string('status', 20)->default('requested')->index();

            // {order_line_uuid: quantity} — the buyer's ask. @see the class note.
            $table->json('line_quantities');

            /*
            | THE SELLER'S SHIPPING INSTRUCTIONS, both set on approval and both
            | meaningless before it. `return_code` is free text because it is
            | whatever the merchant's carrier contract calls it — a code, a
            | reference, an address line — and v1 does not track the return
            | parcel (Shipping v1's manual philosophy, ADR-063).
            */
            $table->string('return_code')->nullable();
            $table->uuid('cargo_company_uuid')->nullable();

            // What a REJECTED buyer is shown. Refusing without a word is the
            // support ticket this column exists to prevent.
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            // THE MOMENT THE MONEY MOVED — the fact ADR-073 exists to record.
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX return_requests_one_open
                 ON return_requests (order_uuid)
                 WHERE status IN ('requested', 'approved')",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};
