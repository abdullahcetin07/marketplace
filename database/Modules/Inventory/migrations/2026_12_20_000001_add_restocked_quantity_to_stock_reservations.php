<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a sold hold has come back (S4, amends ADR-049 again).
 *
 * P5 GAVE INVENTORY A `restock` VERB AND IT WAS ALL-OR-NOTHING, because a refund
 * was all-or-nothing: a whole order came back, so the whole reservation did. S4
 * makes a refund LINE-LEVEL and QUANTITY-LEVEL — a buyer returns one of the two
 * they bought — and "put the units back" stops being a boolean.
 *
 * SO IDEMPOTENCE STOPS BEING A STATUS CHECK. P5 could ask "is this reservation
 * already restocked?"; now it has to ask "how many of these units are still out
 * there?", and this column is that answer. The status still flips to `Restocked`
 * — but only when the last unit is home.
 *
 * DEFAULT ZERO AND BACKFILLED BY IT. Every existing reservation has restocked
 * nothing or everything, and the ones that restocked everything already carry the
 * terminal status — which `RestockAction` checks first, so a stale zero here can
 * never re-open one.
 *
 * @see App\Modules\Inventory\Application\Actions\RestockAction
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->unsignedInteger('restocked_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropColumn('restocked_quantity');
        });
    }
};
