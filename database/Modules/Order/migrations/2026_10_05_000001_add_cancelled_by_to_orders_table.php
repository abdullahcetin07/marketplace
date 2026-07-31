<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-057 — who cancelled, recorded on the order (§3.3).
 *
 * "CANCELLED" WAS ONE WORD FOR FOUR DIFFERENT BUSINESS EVENTS. A customer
 * changing their mind, a seller unable to fulfil, an admin intervening in a
 * dispute, and a checkout quietly timing out all produced the same row — and the
 * seller's notification, the fraud signal, the abandonment metric and the
 * dispute record all need to tell them apart.
 *
 * IT WAS ALREADY ON THE EVENT, and that was not enough. An event is a
 * notification consumed once; this is the fact somebody asks about six months
 * later, when the customer says "I never cancelled that" and the platform has to
 * answer from its own records rather than from a listener's side effect.
 *
 * A STRING, NOT AN ENUM COLUMN. The four values are `CancelOrderDTO`'s constants
 * and the set is a code concern (adding one means writing code to handle it), so
 * the "enum or table?" rule says enum — but the column stays a plain string for
 * the reason every status column on this platform does: a value written last year
 * must still read back after the enum is extended, and a database-level
 * constraint would turn a deploy-order mistake into a write failure on a
 * cancellation.
 *
 * NULLABLE, because orders cancelled before this migration have no answer and
 * inventing one would be worse than admitting it.
 *
 * @see App\Modules\Order\Domain\DTOs\CancelOrderDTO
 * @see docs/modules/Order.md §3.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('cancelled_by', 20)->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('cancelled_by');
        });
    }
};
