<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reservation reference is the CALLER'S OWN STRING KEY, not a uuid
 * (ADR-049 amendment, 2026-07-31).
 *
 * WHAT WENT WRONG, because it is worth writing down. ADR-049 shipped
 * `reference_uuid` as a native `uuid` column, on the reasonable assumption that a
 * caller would key a hold on something it already had — an order's uuid. ADR-057
 * then made the reference per LINE (`{order_uuid}:{variant_uuid}`), because an
 * Inventory reservation is unique per reference and an order with two lines
 * sharing one would silently leave the second unheld.
 *
 * Both decisions were right. Together they were a crash: that composite is not a
 * uuid, and PostgreSQL refuses it. **Every checkout 500'd in production** while
 * the whole suite stayed green — because the suite runs on SQLite, where `uuid`
 * degrades to text and the composite sails through. The same driver blind spot
 * Inventory §12.3 already recorded for its CHECK constraint, this time with a
 * consequence nobody could miss.
 *
 * THE COLUMN NOW MATCHES THE CONTRACT'S OWN WORDS. It always said "the caller
 * passes its own key"; a key that must be a uuid is not the caller's own, it is
 * Inventory's format imposed on it. So: a string, and named `reference` like the
 * movement ledger's column beside it — which was a string all along, and whose
 * disagreement with this one was the clue.
 *
 * READABILITY IS THE POINT OF CHOOSING THIS OVER A HASHED uuid5. The other fix
 * was to derive a real uuid from the composite; it would have kept the type and
 * lost the thing the ledger exists for — `{order}:{variant}` tells a support
 * agent which order and which variant a hold belongs to, and a hash tells them
 * nothing.
 *
 * THE UNIQUE INDEX SURVIVES, and that is what still makes reserve/release/commit
 * idempotent on the reference.
 *
 * @see App\Core\Domain\Contracts\InventoryReservationContract
 * @see docs/Architecture_Decision_Record.md ADR-049
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | RENAME AND RETYPE AS TWO STEPS, in that order, because doing both at once
        | is exactly where the drivers differ: `->change()` on a renamed column
        | needs the new name to already exist.
        |
        | The rename carries the unique index with it on both engines; the retype
        | is what actually widens the column.
        */
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->renameColumn('reference_uuid', 'reference');
        });

        if (DB::getDriverName() === 'pgsql') {
            /*
            | An explicit USING clause. Postgres will not implicitly cast `uuid` to
            | `varchar`, so a plain `ALTER TYPE` fails on a table that already holds
            | rows — and any real deployment does.
            */
            DB::statement('ALTER TABLE stock_reservations ALTER COLUMN reference TYPE varchar(255) USING reference::text');
        } else {
            // SQLite stored it as text from the start; this only makes the
            // declared type match what the other engine now holds.
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->string('reference')->change();
            });
        }
    }

    public function down(): void
    {
        /*
        | THE REVERSE IS LOSSY AND SAYS SO. Any reference written since this
        | migration ran is a composite that will not cast back to `uuid`, so
        | rolling back on a database with real holds fails loudly rather than
        | discarding them — which is the correct failure for a down migration that
        | cannot honestly undo itself.
        */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_reservations ALTER COLUMN reference TYPE uuid USING reference::uuid');
        }

        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->renameColumn('reference', 'reference_uuid');
        });
    }
};
