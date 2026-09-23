<?php

declare(strict_types=1);

use App\Modules\Order\Presentation\Support\OrderPartyLabels;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The order tables' name lookups, on the database production actually runs
|--------------------------------------------------------------------------
|
| **THE UNIT TEST PASSED AND PRODUCTION RETURNED A 500.** `OrderPartyLabels` was
| written with a truncated-uuid fallback for a party that can no longer be
| resolved, and `tests/Modules` proved it — on SQLite, which has no `uuid` type
| and answers "no rows" to any string at all. On PostgreSQL the same input is
| `SQLSTATE[22P02] invalid input syntax for type uuid`: a 500 in a table somebody
| is merely scrolling. Caught on 2026-09-23 by calling it against prod, minutes
| after it shipped.
|
| This platform has now met that exact shape of bug six times (ADR-059,
| `PublicKey`). The guard is one line; the reason it keeps recurring is that the
| suite cannot see it, so the check belongs here.
|
*/

uses(DatabaseTransactions::class);

/**
 * A pgsql connection setting, from the environment or the repo's dev default.
 *
 * `getenv()` rather than `env()`: the helper runs outside the config directory,
 * and `env()` there returns null once the config is cached.
 */
function labelsPgsqlSetting(string $primary, ?string $fallback, string $default): string
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
        'database.connections.pgsql.host' => labelsPgsqlSetting('PGSQL_TEST_HOST', 'DB_HOST', '127.0.0.1'),
        'database.connections.pgsql.port' => labelsPgsqlSetting('PGSQL_TEST_PORT', 'DB_PORT', '5432'),
        'database.connections.pgsql.database' => labelsPgsqlSetting('PGSQL_TEST_DATABASE', null, 'marketplaceos'),
        'database.connections.pgsql.username' => labelsPgsqlSetting('PGSQL_TEST_USERNAME', null, 'marketplaceos'),
        'database.connections.pgsql.password' => labelsPgsqlSetting('PGSQL_TEST_PASSWORD', null, 'secret'),
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

it('answers rather than throwing when a party identifier is not a uuid', function (string $value): void {
    $labels = app(OrderPartyLabels::class);

    // No query is made at all, which is the point: reaching the `uuid` column
    // with this is SQLSTATE[22P02], and an oversight table must not 500 because
    // one row holds something odd.
    expect($labels->storeName($value))->not->toBe('')
        ->and($labels->customerName($value))->not->toBe('');
})->with([
    'plain text' => 'yok-boyle-bir-magaza',
    'an internal id' => '1774',
    'empty' => '',
    'almost a uuid' => '9f00bf2a-cd94-42e5-aa10-50b3b8f7cdd',
]);

it('still resolves a real uuid against the real column type', function (): void {
    $store = DB::connection('pgsql')->table('stores')
        ->whereNull('deleted_at')->where('status', 'active')
        ->first(['uuid', 'name']);

    if ($store === null) {
        $this->markTestSkipped('No active store to resolve.');
    }

    expect(app(OrderPartyLabels::class)->storeName((string) $store->uuid))
        ->toBe((string) $store->name);
});
