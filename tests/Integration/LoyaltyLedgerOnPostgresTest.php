<?php

declare(strict_types=1);

use App\Core\Domain\Contracts\LoyaltyContract;
use App\Modules\Loyalty\Domain\Contracts\LoyaltyLedgerRepositoryContract;
use App\Modules\Loyalty\Domain\Enums\LoyaltyPointSource;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The points ledger, on the database production actually runs
|--------------------------------------------------------------------------
|
| **THE WHOLE SUITE WAS GREEN AND THE FIRST REAL REVERSAL WOULD HAVE THROWN.**
| `loyalty_ledger.source_uuid` was a PostgreSQL `uuid` column, and a refund keys its
| row on `"{group}:{cause}"` — which has to vary per refund so a basket partly
| cancelled and later partly returned credits twice rather than once. That string is
| not a uuid: `SQLSTATE[22P02] invalid input syntax for type uuid`.
|
| SQLite has no uuid type and stored it happily, so nothing in `tests/Modules`
| could see it. This platform has shipped that exact shape of bug three times before
| (ADR-059, `PublicKey`); this file is what stops the fourth from becoming a fifth.
|
*/

uses(DatabaseTransactions::class);

/**
 * A pgsql connection setting, from the environment or the repo's dev default.
 *
 * `getenv()` rather than `env()`: the helper runs outside the config directory, and
 * `env()` there returns null once the config is cached.
 */
function loyaltyPgsqlSetting(string $primary, ?string $fallback, string $default): string
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
    /*
    | THE CONNECTION IS BUILT HERE, NOT READ FROM `.env.testing` — that file points
    | every connection at SQLite `:memory:`, which is the whole reason this test
    | exists.
    */
    config([
        'database.connections.pgsql.host' => loyaltyPgsqlSetting('PGSQL_TEST_HOST', 'DB_HOST', '127.0.0.1'),
        'database.connections.pgsql.port' => loyaltyPgsqlSetting('PGSQL_TEST_PORT', 'DB_PORT', '5432'),
        'database.connections.pgsql.database' => loyaltyPgsqlSetting('PGSQL_TEST_DATABASE', null, 'marketplaceos'),
        'database.connections.pgsql.username' => loyaltyPgsqlSetting('PGSQL_TEST_USERNAME', null, 'marketplaceos'),
        'database.connections.pgsql.password' => loyaltyPgsqlSetting('PGSQL_TEST_PASSWORD', null, 'secret'),
    ]);

    DB::purge('pgsql');

    try {
        DB::connection('pgsql')->getPdo();
    } catch (Throwable $exception) {
        $this->markTestSkipped('PostgreSQL is not reachable: '.$exception->getMessage());
    }

    config(['database.default' => 'pgsql']);
    DB::setDefaultConnection('pgsql');

    /*
    | **AN EXPLICIT TRANSACTION, BECAUSE `DatabaseTransactions` ALONE DID NOT HOLD
    | HERE.** This file writes to the database the application actually runs on;
    | the first version of it left fourteen rows in the live ledger. The trait
    | wraps the DEFAULT connection as it was at boot — which is SQLite — so the
    | pgsql work fell outside it entirely.
    */
    DB::connection('pgsql')->beginTransaction();
});

afterEach(function (): void {
    if (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->rollBack();
    }
});

it('accepts a reversal key that is not a uuid', function (): void {
    $customer = (string) Str::uuid();
    $group = (string) Str::uuid();

    $ledger = app(LoyaltyLedgerRepositoryContract::class);

    $ledger->create([
        'customer_uuid' => $customer,
        'points' => 500,
        'source_type' => LoyaltyPointSource::Signup,
        'source_uuid' => $customer,
        'created_at' => now(),
    ]);

    app(LoyaltyContract::class)->hold($customer, 300, $group);
    app(LoyaltyContract::class)->commit($group);

    /*
     * THE LINE THAT THREW. Everything above works on either driver; this is the
     * one that needs the column to hold `"{uuid}:return"`.
     */
    $back = app(LoyaltyContract::class)->reverse($group, 'return', 1.0, (string) Str::uuid());

    expect($back)->toBe(300)
        ->and($ledger->balanceFor($customer))->toBe(500);
});

it('lets one basket be reversed twice under different causes', function (): void {
    /*
     * **THE REASON THE KEY IS THE REFUND, NOT THE GROUP OR THE CAUSE.** A basket
     * can be partly cancelled before shipping and partly returned after
     * (ADR-065/073) — and it can be returned TWICE, which is what the audit caught:
     * keying on group+cause made the second return a duplicate and silently kept
     * the customer's points.
     *
     * The fractions below are CUMULATIVE and each call credits the delta, so the
     * two together land on exactly the quarter-then-half of what was spent.
     */
    $customer = (string) Str::uuid();
    $group = (string) Str::uuid();

    $ledger = app(LoyaltyLedgerRepositoryContract::class);

    $ledger->create([
        'customer_uuid' => $customer,
        'points' => 1_000,
        'source_type' => LoyaltyPointSource::Signup,
        'source_uuid' => $customer,
        'created_at' => now(),
    ]);

    app(LoyaltyContract::class)->hold($customer, 400, $group);
    app(LoyaltyContract::class)->commit($group);

    $cancelled = app(LoyaltyContract::class)->reverse($group, 'cancellation', 0.25, (string) Str::uuid());
    // Half the basket refunded IN TOTAL — 200 owed, 100 already back, 100 to credit.
    $returned = app(LoyaltyContract::class)->reverse($group, 'return', 0.5, (string) Str::uuid());

    expect($cancelled)->toBe(100)
        ->and($returned)->toBe(100)
        ->and($ledger->balanceFor($customer))->toBe(1_000 - 400 + 200);
});
