<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which basket a redemption or a reversal belongs to (ADR-084).
 *
 * **THE REVERSAL KEY HAD TO STOP BEING THE GROUP, SO SOMETHING ELSE HAS TO CARRY
 * IT.** A refund now keys its row on the individual `PaymentRefund` uuid, because
 * two partial RETURNS of one order shared `"{group}:return"` and the unique index
 * silently dropped the second — the customer was shorted half their points
 * (security audit, 2026-08-18).
 *
 * Keying per refund alone would have been the opposite bug: the second refund's
 * `fully = true` branch wants `floor(spent × 1.0)` and would have credited it ON TOP
 * of the first, a 1.5× over-credit at the platform's expense. So the credit is a
 * DELTA against what this basket has already had back — and that sum needs the
 * basket, which is what this column is.
 *
 * Nullable because the three EARN sources have no basket; indexed because the delta
 * is read on every refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_ledger', function (Blueprint $table): void {
            $table->string('group_uuid', 191)->nullable()->after('source_uuid');
            $table->index(['group_uuid', 'source_type'], 'loyalty_ledger_group_source_index');
        });

        /*
        | BACKFILL THE ROWS THAT ALREADY KNOW. A redemption's `source_uuid` IS the
        | checkout group, so every spend already committed can fill this in — and
        | any reversal written under the old composite key carries the group as its
        | prefix. Without this a refund of an order paid before today would compute
        | its delta against an empty history and re-credit from scratch.
        */
        DB::table('loyalty_ledger')->where('source_type', 'redemption')
            ->update(['group_uuid' => DB::raw('source_uuid')]);

        foreach (DB::table('loyalty_ledger')->where('source_type', 'reversal')->get(['id', 'source_uuid']) as $row) {
            $group = str_contains((string) $row->source_uuid, ':')
                ? explode(':', (string) $row->source_uuid)[0]
                : (string) $row->source_uuid;

            DB::table('loyalty_ledger')->where('id', $row->id)->update(['group_uuid' => $group]);
        }
    }

    public function down(): void
    {
        Schema::table('loyalty_ledger', function (Blueprint $table): void {
            $table->dropIndex('loyalty_ledger_group_source_index');
            $table->dropColumn('group_uuid');
        });
    }
};
