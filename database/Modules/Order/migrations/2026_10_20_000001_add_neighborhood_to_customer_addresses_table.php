<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mahalle — a third free-text level between `district` and `line1`
 * (ADR-056 amendment, 2026-08-03).
 *
 * WHY A TURKISH ADDRESS NEEDS IT. "Kadıköy / İstanbul" plus a street is not a
 * deliverable address here: the mahalle is what a courier routes on, and it is
 * printed on every official Turkish address between the ilçe and the street. A
 * customer typing it into `line1` works right up until somebody has to sort
 * parcels by it.
 *
 * NULLABLE, AND THAT IS THE WHOLE COMPATIBILITY STORY. ADR-056 made this address
 * country-agnostic on purpose — `city` and `district` are loose strings because
 * validating world addresses structurally is a project of its own — and a
 * REQUIRED mahalle would quietly make the table Turkish. A German address has no
 * mahalle and keeps none; every address already saved keeps working untouched.
 *
 * NOT A FOREIGN KEY into the new `geo_neighborhoods` table, deliberately, and
 * this is the decision worth defending. The geo tables are a REFERENCE DATASET
 * for client dropdowns, not a validator: a neighbourhood is renamed, merged or
 * created by administrative act, and a stored address must not become invalid —
 * or worse, unreadable — because the registry moved on. The same reasoning that
 * keeps `city` a string keeps this one. It is also what lets a client send any
 * string at all, which non-TR clients must be able to do.
 *
 * @see docs/Architecture_Decision_Record.md ADR-056
 * @see docs/modules/Order.md §2.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table): void {
            // After `district`, matching the order a Turkish address is written
            // and read in: il → ilçe → mahalle → sokak.
            $table->string('neighborhood')->nullable()->after('district');
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->dropColumn('neighborhood');
        });
    }
};
