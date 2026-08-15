<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points earmarked for a checkout that has not finished (ADR-084).
 *
 * **A SEPARATE TABLE FROM THE LEDGER, WHICH IS THE WHOLE POINT.** The ledger is
 * append-only and records what HAPPENED; a hold is a claim on what might. Writing
 * holds into it would make the balance a sum of intentions, and every abandoned
 * checkout would need a compensating row to undo a spend that never occurred.
 *
 * **ONE ROW PER CHECKOUT GROUP, ENFORCED.** A shopper who refreshes the payment
 * page or a client retrying a timed-out request must RE-hold the same points, never
 * stack a second claim on the same balance — and the unique index is what decides
 * that rather than a check somebody remembered.
 *
 * **IT IS DELETED, NOT SOFT-DELETED.** A released hold is not history: nothing was
 * spent, so there is nothing to keep. What happened is in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('checkout_group_uuid')->unique();
            $table->uuid('customer_uuid')->index();
            $table->integer('points');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_holds');
    }
};
