<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer paid with points (ADR-084).
 *
 * **THE DISCOUNT LIVES ON THE PAYMENT AND NOWHERE ELSE.** It is the PLATFORM's
 * cost: every seller-order still settles on its full amount, and commission and KDV
 * are computed from those (ADR-061). Writing the discount into a seller-order or a
 * commission line would quietly move the cost onto the merchant, which is the one
 * thing this feature must not do.
 *
 * **`amount_minor` STAYS THE CARD CHARGE.** It is what PayTR was asked for and what
 * the callback verifies against, so a points-funded discount reduces it rather than
 * sitting beside it. When points cover everything, `amount_minor` is zero and there
 * was no card money — which is exactly what a refund needs to know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // A COUNT, not money (ADR-005 does not apply): points are things.
            $table->integer('points_spent')->default(0)->after('amount_minor');
            // The lira those points bought, in kuruş — the platform's cost.
            $table->integer('discount_minor')->default(0)->after('points_spent');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['points_spent', 'discount_minor']);
        });
    }
};
