<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One record that we have already asked this buyer about this purchase (ADR-087).
 *
 * **`order_line_uuid` IS UNIQUE, AND THAT INDEX IS THE WHOLE GUARANTEE.** The
 * sweep runs nightly and Order re-offers every delivered line every time — by
 * design, because a reader that filtered on somebody else's table would be Order
 * reaching into Reviews. So "have we asked already" is answered here, and it is
 * answered twice: the action checks before sending and the database refuses a
 * second row if two runs ever overlap. A check alone is a race; a constraint
 * alone is an exception in a sweep. The pattern is the loyalty ledger's.
 *
 * **A ROW MEANS "HANDLED", NOT "SENT".** `sent_at` is null when the invitation was
 * deliberately suppressed — an unsubscribed buyer, the feature switched off — and
 * `suppressed_reason` says which. Recording it is what stops the sweep
 * re-evaluating the same declining customer every night forever, and the count of
 * suppressions is the only measure the platform has of how much of its review
 * funnel opt-out is costing.
 *
 * **NOT ONE FOREIGN KEY LEAVES THIS MODULE**, the same rule the `reviews` table
 * states: the line, the customer and the product are bare uuids. Reviews imports
 * no module and this table does not become the exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('order_line_uuid')->unique();
            $table->uuid('customer_uuid')->index();
            $table->uuid('product_uuid')->index();

            // Null when suppressed. Together with the reason it is the difference
            // between "we asked" and "we decided not to".
            $table->timestamp('sent_at')->nullable();
            $table->string('suppressed_reason', 40)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
    }
};
