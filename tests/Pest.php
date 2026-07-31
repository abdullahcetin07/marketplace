<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case Binding
|--------------------------------------------------------------------------
|
| Feature and Modules tests get a database; Unit tests deliberately do not.
| A "unit" test that touches the database is an integration test wearing the
| wrong label, and the missing RefreshDatabase here is what makes that
| mistake fail loudly instead of just being slow.
|
| Architecture tests need neither — they inspect code, not behaviour.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Modules');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
| Integration tests run against the REAL database (see phpunit.xml), so they get
| the test case but NOT `RefreshDatabase`: that trait drops and re-migrates, which
| is exactly what must never happen to a database somebody else is using. Each
| file wraps itself in a transaction instead, and skips when the engine is not
| reachable.
*/
pest()->extend(TestCase::class)
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a value is a backed enum case of the given enum.
 */
expect()->extend('toBeEnumOf', function (string $enum) {
    expect($this->value)->toBeInstanceOf($enum);

    return $this;
});

/**
 * Assert a closure runs within a query budget.
 *
 * The point of strict mode is that N+1 queries throw in development — but a
 * query count that creeps from 3 to 30 without ever lazy loading is still a
 * regression, and only an explicit budget catches it.
 */
expect()->extend('toRunQueries', function (int $max) {
    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    ($this->value)();

    expect($count)->toBeLessThanOrEqual(
        $max,
        "Expected at most {$max} queries, {$count} were executed.",
    );

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Fully-qualify a first-party namespace fragment.
 */
function appNamespace(string $suffix = ''): string
{
    return 'App\\'.ltrim($suffix, '\\');
}
