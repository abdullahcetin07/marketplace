<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `source_uuid` becomes a source IDENTIFIER, not a uuid (ADR-084).
 *
 * **A REVERSAL IS KEYED BY MORE THAN A UUID, AND PostgreSQL SAID SO.** The refund
 * path keys its row on the checkout group AND the cause — `"{group}:{cause}"` —
 * because a basket can be partly cancelled and later partly returned, and one key
 * for both would swallow the second refund and quietly keep the customer's points.
 * That string is not a uuid, and a `uuid` column rejects it:
 * `SQLSTATE[22P02] invalid input syntax for type uuid`.
 *
 * **EVERY TEST PASSED.** SQLite has no uuid type and stores the string happily, so
 * the whole suite was green while the first real reversal on PostgreSQL would have
 * thrown. This platform has shipped that exact shape of bug three times before
 * (ADR-059, `PublicKey`); this is the fourth, caught on the server rather than by a
 * customer.
 *
 * The column keeps its unique index with `source_type` — the idempotency guarantee
 * is unchanged — and every existing value is still a uuid, just no longer required
 * to be one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // SQLite (the test path) is typeless enough that there is nothing to
            // widen — and `change()` on it would rebuild the table for no gain.
            return;
        }

        // A CAST IS REQUIRED: PostgreSQL will not silently reinterpret uuid as
        // text, which is the same strictness that surfaced the bug.
        DB::statement('ALTER TABLE loyalty_ledger ALTER COLUMN source_uuid TYPE varchar(191) USING source_uuid::text');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE loyalty_ledger ALTER COLUMN source_uuid TYPE uuid USING source_uuid::uuid');
    }
};
