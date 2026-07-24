<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * ADR-012: `users.name` becomes `first_name` + `last_name`.
 *
 * WHY A NEW MIGRATION RATHER THAN EDITING THE CREATE:
 * `003_Database_Standards.md` §27 — "Never edit old migrations. Create new
 * migrations for changes." The rule is unconditional. Editing the create would
 * have been simpler on an undeployed schema, but a migration history that is
 * rewritten once is a migration history nobody can trust afterwards.
 *
 * `last_name` is NULLABLE by decision: the platform serves sole traders and
 * individuals in markets where a single given name is normal. Requiring a
 * surname would make those accounts unrepresentable.
 *
 * THERE IS NO `full_name` COLUMN AND NEVER WILL BE. The display name is
 * computed — `User::displayName()`. A denormalised copy is a second source of
 * truth that drifts the first time one side is written alone.
 *
 * BACKFILL IS DONE IN PHP, not SQL, so it behaves identically on PostgreSQL and
 * on the SQLite test connection. `split_part()` and `substring(... from ...)`
 * differ between them, and a migration that only works on one engine is a
 * migration that fails in CI.
 */
return new class extends Migration
{
    /**
     * Backfill counters, reported after the migration completes.
     *
     * Operational visibility only — no business logic depends on these. The
     * point is that an operator running this against production sees what it
     * actually did rather than a bare "DONE".
     *
     * @var array<string, int>
     */
    private array $stats = [
        'processed' => 0,
        'split' => 0,
        'first_name_only' => 0,
    ];

    public function up(): void
    {
        // 1. Add both nullable so the backfill has somewhere to write.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('type');
            $table->string('last_name')->nullable()->after('first_name');
        });

        // 2. Split the existing value on the FIRST space.
        $this->backfill();

        // 3. Promote first_name to NOT NULL now that every row has one.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->nullable(false)->change();
        });

        // 4. Drop the old column last, so a failure above leaves the source
        //    data intact and the migration can be re-run.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });

        $this->report();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('type');
        });

        DB::table('users')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update([
                    'name' => trim($row->first_name.' '.($row->last_name ?? '')),
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();
            $table->dropColumn(['first_name', 'last_name']);
        });
    }

    /**
     * Split `name` on the first space.
     *
     * "Ayşe Yılmaz"        -> first: Ayşe,  last: Yılmaz
     * "Ayşe Nur Yılmaz"    -> first: Ayşe,  last: Nur Yılmaz
     * "Madonna"            -> first: Madonna, last: null
     *
     * Splitting on the FIRST space rather than the last keeps multi-part
     * surnames intact, which is the common case in the markets served. A
     * middle name lands in `last_name`, which is wrong in a small number of
     * cases and recoverable by the user; the alternative loses the surname
     * entirely, which is not.
     */
    private function backfill(): void
    {
        DB::table('users')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $name = trim((string) ($row->name ?? ''));
                $position = mb_strpos($name, ' ');

                if ($position === false) {
                    $first = $name;
                    $last = null;
                } else {
                    $first = mb_substr($name, 0, $position);
                    $last = trim(mb_substr($name, $position + 1));
                }

                $resolvedLast = ($last === null || $last === '') ? null : $last;

                DB::table('users')->where('id', $row->id)->update([
                    // A row with an empty name cannot exist (the column was NOT
                    // NULL) but an all-whitespace one could. Never leave
                    // first_name empty — step 3 would fail on it.
                    'first_name' => $first !== '' ? $first : 'Unknown',
                    'last_name' => $resolvedLast,
                ]);

                $this->stats['processed']++;
                $resolvedLast === null
                    ? $this->stats['first_name_only']++
                    : $this->stats['split']++;
            }
        });
    }

    /**
     * Print what the backfill actually did.
     *
     * Written to the console when one is attached AND to the daily log, so the
     * numbers survive a deploy pipeline that discards stdout. A migration that
     * silently rewrites every user row should not be a black box.
     *
     * Wrapped in a try/catch: a reporting failure must never fail a migration
     * that has already succeeded.
     */
    private function report(): void
    {
        $lines = [
            'ADR-012 user name split complete.',
            sprintf('  total users processed : %d', $this->stats['processed']),
            sprintf('  split into two names  : %d', $this->stats['split']),
            sprintf('  first name only       : %d', $this->stats['first_name_only']),
        ];

        try {
            Log::channel('daily')->info('User name split backfill', $this->stats);

            if (app()->runningInConsole()) {
                $output = new ConsoleOutput;

                foreach ($lines as $line) {
                    $output->writeln($line);
                }
            }
        } catch (Throwable) {
            // Reporting is observability, not correctness. The schema change
            // has already committed.
        }
    }
};
