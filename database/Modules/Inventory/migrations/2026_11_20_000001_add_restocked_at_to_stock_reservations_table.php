<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a committed hold's units came back (Payment.md §8, P5).
 *
 * THE COLUMN ORDER.md §12.5 SAID WOULD BE NEEDED. That follow-up recorded that
 * post-payment restock "genuinely does need an Inventory reversal primitive with
 * its own movement type" and deferred it until a module could actually refund.
 * Payment now can, so it lands.
 *
 * A THIRD TIMESTAMP RATHER THAN REUSING `released_at`, for the same reason the
 * status is a fourth case and not a return to `released`: a hold that never
 * became a sale and a sale that was undone are different facts, and a
 * reservation's history is exactly where a dispute is settled.
 *
 * @see App\Modules\Inventory\Application\Actions\RestockAction
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->timestampTz('restocked_at')->nullable()->after('committed_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropColumn('restocked_at');
        });
    }
};
