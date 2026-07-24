<?php

declare(strict_types=1);

use App\Modules\Localization\Domain\Contracts\CurrencyRepositoryContract;
use App\Modules\Localization\Domain\Enums\SymbolPosition;
use App\Modules\Localization\Domain\Models\Currency;

/*
| Money handling. Currency became a lookup table in Sprint 1, but the rule it
| enforces did not change: amounts are integers of minor units, never floats.
| @see docs/001_Architecture.md §7
*/

it('converts major units to minor units', function (): void {
    $try = Currency::factory()->turkishLira()->make();

    expect($try->toMinor(1499.00))->toBe(149900)
        ->and($try->toMinor('19.99'))->toBe(1999)
        ->and($try->toMinor(0.01))->toBe(1);
});

it('rounds half-up at the last minor digit', function (): void {
    // 0.005 is not exactly representable in binary floating point. This is the
    // case that silently loses a kuruş if the implementation truncates.
    $try = Currency::factory()->turkishLira()->make();

    expect($try->toMinor(10.005))->toBe(1001)
        ->and($try->toMinor(10.004))->toBe(1000);
});

it('round-trips through minor units without drift', function (): void {
    $try = Currency::factory()->turkishLira()->make();

    foreach ([0, 1, 99, 100, 149900, 999999999] as $minor) {
        expect($try->toMinor($try->toMajor($minor)))->toBe($minor);
    }
});

it('formats using its own separators and symbol position', function (): void {
    $try = Currency::factory()->turkishLira()->make();
    $usd = Currency::factory()->usDollar()->make();

    // Turkish: dot groups thousands, comma marks decimals, symbol trails.
    expect($try->format(149900))->toBe('1.499,00 ₺')
        // US: inverted on both counts, symbol leads.
        ->and($usd->format(1999))->toBe('$19.99');
});

it('formats without a symbol when asked', function (): void {
    expect(Currency::factory()->turkishLira()->make()->format(149900, withSymbol: false))
        ->toBe('1.499,00');
});

it('supports zero-decimal currencies', function (): void {
    // Nothing may assume 2 decimal places. @see Currency::minorUnits()
    $jpy = Currency::factory()->zeroDecimal()->make();

    expect($jpy->factor())->toBe(1)
        ->and($jpy->toMinor(1500))->toBe(1500)
        ->and($jpy->toMajor(1500))->toBe(1500.0);
});

it('converts between currencies through the base rate', function (): void {
    $try = Currency::factory()->turkishLira()->create(['is_default' => true]);
    $usd = Currency::factory()->usDollar()->create();

    // 100.00 TRY at 0.029 => 2.90 USD
    expect($try->convertTo($usd, 10000))->toBe(290);
});

it('returns the amount unchanged when converting to itself', function (): void {
    $try = Currency::factory()->turkishLira()->create(['is_default' => true]);

    expect($try->convertTo($try, 12345))->toBe(12345);
});

it('treats the base currency rate as always fresh', function (): void {
    $default = Currency::factory()->turkishLira()->create(['is_default' => true]);

    // The base currency's rate is 1.0 by definition; it cannot go stale.
    expect($default->hasFreshRate())->toBeTrue();
});

it('reports a stale rate on a non-default currency', function (): void {
    Currency::factory()->turkishLira()->create(['is_default' => true]);
    $stale = Currency::factory()->usDollar()->withStaleRate()->create();

    // A stale rate is worse than a missing one — it silently misprices.
    expect($stale->hasFreshRate())->toBeFalse();
});

it('allows only one default currency', function (): void {
    $first = Currency::factory()->turkishLira()->create(['is_default' => true]);
    $second = Currency::factory()->usDollar()->create(['is_default' => true]);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(Currency::query()->where('is_default', true)->count())->toBe(1);
});

it('normalises the code to uppercase on lookup', function (): void {
    Currency::factory()->turkishLira()->create();

    // ADR-019: cached lookups moved from the model to the repository.
    expect(app(CurrencyRepositoryContract::class)->findByCode('try')?->code)->toBe('TRY');
});

it('caches the default currency behind the repository', function (): void {
    $repository = app(CurrencyRepositoryContract::class);

    Currency::factory()->turkishLira()->create(['is_default' => true]);

    expect($repository->default()->code)->toBe('TRY');

    // The observer flushes on write, so a new default is visible immediately
    // rather than after the hour-long TTL.
    Currency::factory()->usDollar()->create(['is_default' => true]);

    expect($repository->default()->code)->toBe('USD');
});

it('places the symbol according to its configured position', function (): void {
    expect(SymbolPosition::Before->apply('19.99', '$'))->toBe('$19.99')
        ->and(SymbolPosition::After->apply('1.499,00', '₺'))->toBe('1.499,00 ₺');
});
