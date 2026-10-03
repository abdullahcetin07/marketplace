<?php

declare(strict_types=1);

use App\Console\Commands\TimestampSkewAuditCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The timestamp correction, checked by the database that would apply it
|--------------------------------------------------------------------------
|
| A sibling deployment stored lifecycle instants roughly three hours ahead, which
| for this platform is an outage rather than a display defect: an `AwaitingPayment`
| order whose `placed_at` sits past the expiry boundary never expires and holds its
| stock forever (ADR-072), and ADR-064's delivery, return and payout windows all
| open at the wrong moment.
|
| **RAFTABUL MEASURED CLEAN** (2026-10-03) — every lifecycle column `timestamptz`,
| app/PHP/database/OS clocks in agreement, and the nginx access log matching
| `payments.paid_at` to the second. So what is tested here is the TOOL and the
| INVARIANT, not a repair: the generator must refuse to touch a healthy database,
| and the schema must keep the shape that makes the bug impossible.
|
| **IT CANNOT RUN ON SQLITE.** `AT TIME ZONE` is the whole subject, and the default
| suite's SQLite has neither that operator nor a `timestamptz` type — it would agree
| with any expression at all, including a backwards one.
|
*/

uses(DatabaseTransactions::class);

/**
 * A pgsql connection setting, from the environment or the repo's dev default.
 *
 * `getenv()` rather than `env()`: the helper runs outside the config directory,
 * and `env()` there returns null once the config is cached.
 */
function skewPgsqlSetting(string $primary, ?string $fallback, string $default): string
{
    foreach (array_filter([$primary, $fallback]) as $candidate) {
        $value = getenv($candidate);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return $default;
}

beforeEach(function (): void {
    config([
        'database.connections.pgsql.host' => skewPgsqlSetting('PGSQL_TEST_HOST', 'DB_HOST', '127.0.0.1'),
        'database.connections.pgsql.port' => skewPgsqlSetting('PGSQL_TEST_PORT', 'DB_PORT', '5432'),
        'database.connections.pgsql.database' => skewPgsqlSetting('PGSQL_TEST_DATABASE', null, 'marketplaceos'),
        'database.connections.pgsql.username' => skewPgsqlSetting('PGSQL_TEST_USERNAME', null, 'marketplaceos'),
        'database.connections.pgsql.password' => skewPgsqlSetting('PGSQL_TEST_PASSWORD', null, 'secret'),
    ]);

    DB::purge('pgsql');

    try {
        DB::connection('pgsql')->getPdo();
    } catch (Throwable $exception) {
        $this->markTestSkipped('PostgreSQL is not reachable: '.$exception->getMessage());
    }

    config(['database.default' => 'pgsql']);
    DB::setDefaultConnection('pgsql');

    // The trait wraps the connection as it was at boot — SQLite — so the pgsql
    // work would fall outside it entirely without this.
    DB::connection('pgsql')->beginTransaction();
});

afterEach(function (): void {
    if (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->rollBack();
    }
});

/**
 * What the generated expression actually does to one instant, in seconds.
 *
 * Positive means the correction moved the instant EARLIER — the repair for data
 * stored ahead of the truth.
 */
function skewShiftSeconds(string $type, string $direction, string $stored): int
{
    $expression = TimestampSkewAuditCommand::expression('t', $type, 'Europe/Istanbul', $direction);

    $cast = str_contains($type, 'with time zone') ? 'timestamptz' : 'timestamp';

    $row = DB::connection('pgsql')->selectOne(
        sprintf('select "t"::timestamptz as before, (%s)::timestamptz as after from (select ?::%s as t) s', $expression, $cast),
        [$stored],
    );

    return (new DateTimeImmutable((string) $row->before))->getTimestamp()
        - (new DateTimeImmutable((string) $row->after))->getTimestamp();
}

it('moves the instant the correct way for each column type', function (string $type, string $direction, int $expected): void {
    /*
     * THE TWO COLUMN TYPES NEED OPPOSITE SQL FOR THE SAME REPAIR. Reading a stored
     * value as UTC and re-stating it in Istanbul subtracts the offset from a
     * `timestamptz` and ADDS it to a bare `timestamp`. A generator that emitted one
     * form for both would correct half the schema and double the error on the rest
     * — and the runbook would look equally plausible either way.
     */
    expect(skewShiftSeconds($type, $direction, '2026-07-15 13:00:00'))->toBe($expected);
})->with([
    'aware column, stored ahead' => ['timestamp with time zone', 'back', 10800],
    'aware column, stored behind' => ['timestamp with time zone', 'forward', -10800],
    'naive column, stored ahead' => ['timestamp without time zone', 'back', 10800],
    'naive column, stored behind' => ['timestamp without time zone', 'forward', -10800],
]);

it('uses each row own offset instead of a fixed interval', function (): void {
    /*
     * Turkey abolished daylight saving in 2016, so every row this platform has ever
     * written is UTC+03 and a hard-coded `- interval '3 hours'` would happen to be
     * right. It is still wrong to write: the same expression is the repair for data
     * that predates 2016 or lives in another zone, where the offset is a property of
     * the ROW'S OWN DATE. January 2015 in Istanbul was +02 and July 2015 was +03 —
     * if these two came back equal, the generator would be applying a constant.
     */
    $winter = skewShiftSeconds('timestamp with time zone', 'back', '2015-01-15 13:00:00');
    $summer = skewShiftSeconds('timestamp with time zone', 'back', '2015-07-15 13:00:00');

    expect($winter)->toBe(7200)
        ->and($summer)->toBe(10800)
        ->and($winter)->not->toBe($summer);
});

it('keeps every lifecycle instant in a timezone-aware column', function (): void {
    /*
     * THIS IS THE INVARIANT THAT MAKES THE BUG IMPOSSIBLE, and the reason it is
     * asserted rather than assumed. A `timestamptz` stores the instant with its
     * offset, so no amount of application misconfiguration can make the database
     * disagree about WHEN something happened. A bare `timestamp` stores a wall
     * clock whose meaning depends on whoever reads it, which is precisely how three
     * hours goes missing.
     *
     * A new migration reaching for `timestamp` on any of these tables fails here.
     */
    $naive = DB::connection('pgsql')->select(
        "select table_name, column_name from information_schema.columns
         where table_schema = current_schema()
         and data_type = 'timestamp without time zone'
         and column_name like '%\\_at'
         and table_name in ('orders', 'order_lines', 'payments', 'payment_refunds',
                            'shipments', 'return_requests', 'cancellation_requests',
                            'seller_ledger_entries', 'seller_payouts')
         order by table_name, column_name",
    );

    $offenders = array_map(
        static fn (object $row): string => $row->table_name.'.'.$row->column_name,
        $naive,
    );

    expect($offenders)->toBe([]);
});

it('refuses to emit a backfill for a database with no skew', function (): void {
    /*
     * The dangerous mode is the one that looks harmless. Asked for a runbook
     * against healthy data the command must FAIL rather than print SQL an operator
     * could paste in good faith — applying it would move every instant in the money
     * path by a full zone offset, which is the bug rather than its repair.
     */
    $this->artisan('timestamps:audit', ['--runbook' => 'storage/app/does-not-matter.json'])
        ->expectsOutputToContain('Refusing to emit a runbook')
        ->assertFailed();
});

it('emits one all-or-nothing transaction guarded by a marker row', function (): void {
    /*
     * `--force` is the escape hatch for the deployment where the skew is real but
     * this database is not it, so the emitted SQL still has to be right. It writes
     * nothing — the command only ever PRINTS the statements — and what it prints is
     * what an operator will paste into a maintenance window, so the shape is the
     * deliverable and belongs under test.
     *
     * **ONE TRANSACTION FOR EVERY TABLE.** Per-table transactions would let a
     * failure land with the orders corrected and their payments not; a money path
     * that disagrees with itself is worse than one uniformly three hours out, and
     * far harder to unpick afterwards.
     *
     * **THE MARKER IS THE IDEMPOTENCY GUARD**, not a log line: it is keyed by table
     * name and inserted in the same transaction as the update, so a second run
     * collides on the primary key, aborts, and moves nothing. Re-applying would
     * shift correct data by another full offset.
     */
    $path = storage_path('app/timestamp-frontier-test.json');

    file_put_contents($path, (string) json_encode([
        'captured_at' => now()->toIso8601String(),
        'zone' => 'Europe/Istanbul',
        'cutoffs' => ['orders' => 35, 'payments' => 2],
    ], JSON_THROW_ON_ERROR));

    try {
        /*
         * `Artisan::output()` rather than `$this->artisan(...)->expectsOutputToContain()`:
         * that chain registers one Mockery expectation per substring against the same
         * `writeln`, and the first matching one consumes the call, so a correct
         * multi-fragment runbook fails the later assertions. Capturing the text and
         * asserting on it answers the actual question.
         */
        $exit = Artisan::call('timestamps:audit', [
            '--runbook' => $path,
            '--force' => true,
            '--zone' => 'Europe/Istanbul',
        ]);

        $sql = Artisan::output();

        expect($exit)->toBe(0)
            ->and($sql)->toContain('CREATE TABLE IF NOT EXISTS timestamp_backfills')
            ->and($sql)->toContain('table_name text PRIMARY KEY')
            ->and($sql)->toContain("VALUES ('orders', 35, 'Europe/Istanbul');")
            // The aware-column repair, and the frontier as an id rather than a date.
            ->and($sql)->toContain('"placed_at" = "placed_at" AT TIME ZONE \'UTC\' AT TIME ZONE \'Europe/Istanbul\'')
            ->and($sql)->toContain('WHERE id <= 35;')
            // Exactly one transaction, wrapping every table.
            ->and(substr_count($sql, 'BEGIN;'))->toBe(1)
            ->and(substr_count($sql, 'COMMIT;'))->toBe(1);
    } finally {
        @unlink($path);
    }
});
