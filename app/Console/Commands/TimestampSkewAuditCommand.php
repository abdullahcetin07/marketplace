<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Proves whether stored lifecycle instants are shifted, and only then writes a
 * backfill runbook for an operator to run.
 *
 * **WHY IT EXISTS.** A sibling deployment stored timestamps misinterpreted and
 * roughly three hours ahead. The damage is not cosmetic: an `AwaitingPayment`
 * order whose `placed_at` sits ahead of the expiry boundary never expires, so its
 * stock is held forever (ADR-072), and ADR-064's delivery, return and payout
 * windows all open and close at the wrong moment.
 *
 * **IT DIAGNOSES BEFORE IT PROPOSES, AND REFUSES WHEN THERE IS NOTHING TO FIX.**
 * Run against a healthy database this command reports "no skew" and will not emit
 * SQL. That is the whole design: the correction below moves every instant in the
 * money path by a full zone offset, so running it where the data is already right
 * is not a no-op, it IS the bug. Raftabul measured clean on 2026-10-03 — every
 * lifecycle column `timestamptz`, app/DB/OS clocks agreed, and the nginx access log
 * matched `payments.paid_at` to the second.
 *
 * **THE CORRECTION DEPENDS ON THE COLUMN TYPE, AND THE TWO ARE OPPOSITES.** For a
 * `timestamptz`, `AT TIME ZONE 'UTC' AT TIME ZONE '<zone>'` moves the instant BACK
 * by the offset; for a `timestamp without time zone` the identical expression moves
 * it FORWARD. Copying one table's SQL onto the other type silently doubles the
 * error instead of clearing it, so the expression is derived per column from the
 * schema and never typed by hand.
 *
 * **IT IS DST-SAFE BECAUSE IT NEVER SUBTRACTS AN INTERVAL.** `AT TIME ZONE` resolves
 * the offset in force on each row's own date, so rows written either side of a
 * clock change are each corrected by their own offset. A fixed `- interval '3 hours'`
 * would be wrong for every winter row.
 *
 * **THE BOUNDARY IS AN ID, NOT A TIMESTAMP.** The whole premise is that the stored
 * times are wrong, so `created_at < '<deploy>'` cannot separate corrected writes from
 * broken ones — a three-hour error overlaps the cutoff in both directions. `id` is
 * `bigserial` and monotonic, so `max(id)` captured BEFORE the fix is an exact
 * frontier. Capturing it is `--snapshot`, and `--runbook` refuses without one.
 *
 * **RUNNING IT TWICE IS STOPPED BY THE DATABASE, NOT BY A NOTE IN THE RUNBOOK.** The
 * emitted SQL inserts a marker row keyed by table name inside the same transaction
 * as the update; a second run collides on the primary key, the transaction aborts,
 * and no row moves. A second application would shift correct data by another offset.
 *
 * Reports by default. It writes nothing to the database in any mode — `--runbook`
 * prints SQL for an operator to review and run during a maintenance window.
 */
final class TimestampSkewAuditCommand extends Command
{
    /**
     * The money and fulfilment path — the tables where a shifted instant changes
     * what the platform DOES, not merely what a report displays.
     *
     * Deliberately not "every table with a timestamp": `imports`, `exports` and
     * `review_requests` carry times nothing branches on, and widening the blast
     * radius of an UPDATE to buy tidiness is the wrong trade.
     *
     * @var array<int, string>
     */
    private const SCOPE = [
        'orders',
        'payments',
        'payment_refunds',
        'shipments',
        'return_requests',
        'cancellation_requests',
        'seller_ledger_entries',
        'seller_payouts',
    ];

    /**
     * Instants that cannot legally precede each other, per table.
     *
     * A PARTIAL shift is what this catches: if one column was corrected and its
     * neighbour was not, an order is delivered before it was shipped. A uniform
     * shift leaves all of these intact, which is why it is not the only check.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const ORDERING = [
        ['orders', 'created_at', 'placed_at'],
        ['orders', 'placed_at', 'cancelled_at'],
        ['payments', 'created_at', 'paid_at'],
        ['payments', 'created_at', 'failed_at'],
        ['payments', 'paid_at', 'refunded_at'],
        ['shipments', 'created_at', 'shipped_at'],
        ['shipments', 'shipped_at', 'delivered_at'],
        ['shipments', 'delivered_at', 'returned_at'],
        ['return_requests', 'created_at', 'decided_at'],
        ['return_requests', 'decided_at', 'completed_at'],
        ['cancellation_requests', 'created_at', 'decided_at'],
    ];

    protected $signature = 'timestamps:audit
                            {--zone= : Zone the data should read in (default: config app.timezone)}
                            {--snapshot= : Capture the pre-deploy max(id) frontier to this JSON file}
                            {--runbook= : Emit backfill SQL using the frontier captured at this path}
                            {--direction=back : Stored instants are ahead (back) or behind (forward)}
                            {--force : Emit a runbook even though no skew was detected}';

    protected $description = 'Detect shifted lifecycle timestamps and generate a reviewed backfill runbook';

    public function handle(): int
    {
        $zone = (string) ($this->option('zone') ?? '') ?: (string) config('app.timezone');

        $columns = $this->lifecycleColumns();

        if ($columns === []) {
            $this->warn('None of the scoped tables exist on this connection.');

            return self::SUCCESS;
        }

        $this->clocks($zone);

        $findings = $this->findings($columns);

        $this->newLine();
        $this->line($findings === []
            ? '<info>No skew detected.</info> Every check below passed.'
            : '<comment>Skew indicators:</comment>');

        foreach ($findings as $finding) {
            $this->line('  • '.$finding);
        }

        if (is_string($this->option('snapshot'))) {
            return $this->snapshot($columns, $zone);
        }

        if (is_string($this->option('runbook'))) {
            return $this->runbook($columns, $zone, $findings);
        }

        $this->newLine();
        $this->line('Next: --snapshot=<path> before deploying the code fix, then --runbook=<path>.');

        return self::SUCCESS;
    }

    /**
     * The correction for one column, derived from its type.
     *
     * The two branches are not a tidiness preference. On a `timestamptz` the
     * UTC-first form moves the instant back by the zone offset; on a bare
     * `timestamp` the SAME form moves it forward. One expression for both column
     * types would correct half the schema and double the error on the rest.
     *
     * **PUBLIC BECAUSE IT IS THE ONE LINE HERE THAT FAILS SILENTLY.** Every other
     * mistake in this command produces an obvious wrong answer; getting this
     * backwards produces a confident runbook that doubles the error. The test
     * hands both column types to PostgreSQL and checks which way the instant
     * actually moved.
     *
     * @see tests/Integration/TimestampSkewAuditOnPostgresTest
     */
    public static function expression(string $column, string $type, string $zone, string $direction): string
    {
        $aware = str_contains($type, 'with time zone');

        // Reading the stored value as UTC and re-stating it in `$zone` subtracts
        // the offset for an aware column and adds it for a naive one.
        $utcFirst = $aware ? $direction === 'back' : $direction === 'forward';

        return $utcFirst
            ? sprintf('"%s" AT TIME ZONE \'UTC\' AT TIME ZONE \'%s\'', $column, $zone)
            : sprintf('"%s" AT TIME ZONE \'%s\' AT TIME ZONE \'UTC\'', $column, $zone);
    }

    /**
     * Every `*_at` column on the scoped tables, with the type the correction
     * depends on — read from the schema rather than listed here, because a column
     * this misses is a column the backfill leaves three hours wrong.
     *
     * @return array<string, array<string, string>> table => column => data type
     */
    private function lifecycleColumns(): array
    {
        $found = [];

        foreach (self::SCOPE as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::select(
                "select column_name, data_type from information_schema.columns
                 where table_schema = current_schema() and table_name = ?
                 and data_type like 'timestamp%' order by column_name",
                [$table],
            );

            foreach ($rows as $row) {
                $found[$table][(string) $row->column_name] = (string) $row->data_type;
            }
        }

        return $found;
    }

    /**
     * The four clocks that must agree. Disagreement is the CAUSE; the findings
     * below are the EVIDENCE — a database can hold shifted history long after the
     * misconfiguration that wrote it was corrected, so both are reported.
     */
    private function clocks(string $zone): void
    {
        $database = DB::selectOne('select now() as at, current_setting(\'TimeZone\') as zone');

        $this->table(['clock', 'value'], [
            ['config app.timezone', (string) config('app.timezone')],
            ['PHP default', date_default_timezone_get()],
            ['PHP now', now()->toDateTimeString()],
            ['database TimeZone', (string) ($database->zone ?? '?')],
            ['database now', (string) ($database->at ?? '?')],
            ['correcting into', $zone],
        ]);
    }

    /**
     * @param array<string, array<string, string>> $columns
     *
     * @return array<int, string>
     */
    private function findings(array $columns): array
    {
        return array_merge(
            $this->futureInstants($columns),
            $this->orderingViolations(),
            $this->unexpiredOrders(),
        );
    }

    /**
     * An instant the platform recorded for something that has not happened yet.
     *
     * This is the single strongest proof of a forward shift and needs no external
     * reference: nothing in the money path may be stamped in the future. The two
     * minutes of slack absorb clock drift between the app and database hosts.
     *
     * @param array<string, array<string, string>> $columns
     *
     * @return array<int, string>
     */
    private function futureInstants(array $columns): array
    {
        $findings = [];

        foreach ($columns as $table => $definitions) {
            foreach (array_keys($definitions) as $column) {
                $count = (int) DB::table($table)
                    ->whereRaw(sprintf('"%s" > now() + interval \'2 minutes\'', $column))
                    ->count();

                if ($count > 0) {
                    $findings[] = sprintf('%s.%s: %d row(s) stamped in the future', $table, $column, $count);
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function orderingViolations(): array
    {
        $findings = [];

        foreach (self::ORDERING as [$table, $earlier, $later]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $earlier) || ! Schema::hasColumn($table, $later)) {
                continue;
            }

            $count = (int) DB::table($table)
                ->whereNotNull($later)
                ->whereRaw(sprintf('"%s" < "%s"', $later, $earlier))
                ->count();

            if ($count > 0) {
                $findings[] = sprintf('%s: %d row(s) where %s precedes %s', $table, $count, $later, $earlier);
            }
        }

        return $findings;
    }

    /**
     * The symptom that makes this an outage rather than a reporting defect: an
     * order past its payment window that the ADR-072 sweep never expired, because
     * its `placed_at` sits on the wrong side of the boundary. Stock stays held and
     * the seller's offer quietly leaves the buy box.
     *
     * @return array<int, string>
     */
    private function unexpiredOrders(): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'placed_at')) {
            return [];
        }

        $minutes = (int) settings('order.payment_window_minutes', 30);

        $count = (int) DB::table('orders')
            ->where('status', 'awaiting_payment')
            ->whereRaw(sprintf('placed_at < now() - interval \'%d minutes\'', $minutes))
            ->count();

        return $count > 0
            ? [sprintf('orders: %d awaiting_payment past the %d-minute window and still unexpired', $count, $minutes)]
            : [];
    }

    /**
     * The frontier, captured BEFORE the code fix ships.
     *
     * @param array<string, array<string, string>> $columns
     */
    private function snapshot(array $columns, string $zone): int
    {
        $path = (string) $this->option('snapshot');

        $cutoffs = [];

        foreach (array_keys($columns) as $table) {
            $cutoffs[$table] = (int) (DB::table($table)->max('id') ?? 0);
        }

        /*
         * A mistyped path is the likeliest thing to go wrong here, and it must
         * read as one line an operator can act on. Laravel promotes the
         * `file_put_contents` warning to an exception, so checking the return
         * value alone would hand them a stack trace instead.
         */
        try {
            file_put_contents($path, (string) json_encode([
                'captured_at' => now()->toIso8601String(),
                'zone' => $zone,
                'cutoffs' => $cutoffs,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            $this->error('Could not write the frontier to '.$path.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['table', 'max(id) frontier'], array_map(
            static fn (string $table): array => [$table, (string) $cutoffs[$table]],
            array_keys($cutoffs),
        ));
        $this->info('Frontier written to '.$path.' — deploy the code fix only after this exists.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, array<string, string>> $columns
     * @param array<int, string> $findings
     */
    private function runbook(array $columns, string $zone, array $findings): int
    {
        if ($findings === [] && $this->option('force') !== true) {
            $this->newLine();
            $this->error('Refusing to emit a runbook: no skew was detected.');
            $this->line('Applying it here would move every instant in the money path by one zone offset.');
            $this->line('Pass --force only if you have evidence this check cannot see.');

            return self::FAILURE;
        }

        $snapshot = $this->readSnapshot((string) $this->option('runbook'));

        if ($snapshot === null) {
            return self::FAILURE;
        }

        $direction = (string) $this->option('direction');

        if (! in_array($direction, ['back', 'forward'], true)) {
            $this->error('--direction must be "back" (stored ahead) or "forward" (stored behind).');

            return self::FAILURE;
        }

        $statements = [];

        foreach ($columns as $table => $definitions) {
            $cutoff = $snapshot[$table] ?? null;

            if ($cutoff === null) {
                $this->warn($table.' is not in the snapshot — skipped. Re-capture the frontier.');

                continue;
            }

            $this->preview($table, $definitions, $zone, $direction, $cutoff);

            $statements[] = $this->statement($table, $definitions, $zone, $direction, $cutoff);
        }

        $this->emit($statements, $zone);

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>|null
     */
    private function readSnapshot(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('No frontier snapshot at '.$path.' — run --snapshot before the fix deploys.');
            $this->line('The boundary cannot be a timestamp: a three-hour error overlaps any date cutoff.');

            return null;
        }

        try {
            /** @var array{cutoffs?: array<string, int>} $decoded */
            $decoded = (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->error('Unreadable snapshot: '.$exception->getMessage());

            return null;
        }

        $cutoffs = $decoded['cutoffs'] ?? [];

        return array_map(static fn (mixed $id): int => (int) $id, $cutoffs);
    }

    /**
     * Row count and three real before/after pairs, computed by the database using
     * the very expression the UPDATE will use — so the operator approves the
     * arithmetic rather than the description of it.
     *
     * @param array<string, string> $definitions
     */
    private function preview(string $table, array $definitions, string $zone, string $direction, int $cutoff): void
    {
        $affected = (int) DB::table($table)->where('id', '<=', $cutoff)->count();

        $this->newLine();
        $this->line(sprintf(
            '<comment>%s</comment> — frontier id <= %d, %d row(s), %d column(s)',
            $table,
            $cutoff,
            $affected,
            count($definitions),
        ));

        $column = array_key_first($definitions);

        if ($column === null || $affected === 0) {
            return;
        }

        $samples = DB::select(sprintf(
            'select id, "%s" as before, %s as after from "%s" where id <= ? and "%s" is not null order by id desc limit 3',
            $column,
            self::expression($column, $definitions[$column], $zone, $direction),
            $table,
            $column,
        ), [$cutoff]);

        $this->table(['id', $column.' (before)', $column.' (after)'], array_map(
            static fn (object $row): array => [
                (string) $row->id,
                (string) $row->before,
                (string) $row->after,
            ],
            $samples,
        ));
    }

    /**
     * @param array<string, string> $definitions
     */
    private function statement(string $table, array $definitions, string $zone, string $direction, int $cutoff): string
    {
        $assignments = [];

        foreach ($definitions as $column => $type) {
            $assignments[] = sprintf(
                '    "%s" = %s',
                $column,
                self::expression($column, $type, $zone, $direction),
            );
        }

        return sprintf(
            "INSERT INTO timestamp_backfills (table_name, cutoff_id, zone)\nVALUES ('%s', %d, '%s');\n\nUPDATE \"%s\" SET\n%s\nWHERE id <= %d;",
            $table,
            $cutoff,
            $zone,
            $table,
            implode(",\n", $assignments),
            $cutoff,
        );
    }

    /**
     * One transaction for every table.
     *
     * Per-table transactions would let the run fail with the orders corrected and
     * their payments not — a platform whose money path disagrees with itself is
     * worse than one uniformly three hours out, and far harder to unpick.
     *
     * @param array<int, string> $statements
     */
    private function emit(array $statements, string $zone): void
    {
        $this->newLine();
        $this->line('<comment>-- Review, back up, then run inside the maintenance window.</comment>');
        $this->newLine();

        $this->line(<<<'SQL'
            -- The marker IS the idempotency guard: keyed by table name and written in
            -- the same transaction as the update, so a second run collides on the
            -- primary key, aborts, and moves nothing.
            CREATE TABLE IF NOT EXISTS timestamp_backfills (
                table_name text PRIMARY KEY,
                cutoff_id  bigint NOT NULL,
                zone       text NOT NULL,
                applied_at timestamptz NOT NULL DEFAULT now()
            );

            BEGIN;
            SQL);

        foreach ($statements as $statement) {
            $this->newLine();
            $this->line($statement);
        }

        $this->newLine();
        $this->line('COMMIT;');
        $this->newLine();
        $this->line(sprintf('-- Verify after COMMIT: php artisan timestamps:audit --zone=%s', $zone));
    }
}
