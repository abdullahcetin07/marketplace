<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementType;

/*
|--------------------------------------------------------------------------
| The strings a seller actually reads
|--------------------------------------------------------------------------
|
| Both panels render movement types through `__("enums.StockMovementType.…")`,
| and a missing key does not fail — Laravel returns the KEY. So the failure mode
| is a seller reading `enums.StockMovementType.committed` in their stock history,
| which is invisible to every other test in the suite and to code review, because
| nothing throws.
|
| Adding a case to either enum is a code change (that is why they are enums and
| not tables), so this is the file that makes "and its label, in both languages"
| part of that change rather than something noticed in production.
|
| No database: labels are files.
|
*/

/**
 * @return array<int, string>
 */
function inventoryEnumCases(): array
{
    return [
        ...array_map(fn (StockMovementType $t): string => "enums.StockMovementType.{$t->value}", StockMovementType::cases()),
        ...array_map(fn (ReservationStatus $s): string => "enums.ReservationStatus.{$s->value}", ReservationStatus::cases()),
    ];
}

it('labels every movement type and reservation status in Turkish', function (): void {
    foreach (inventoryEnumCases() as $key) {
        // A returned key IS the miss — `__()` never throws.
        expect(__($key, [], 'tr'))->not->toBe($key, "missing tr label: {$key}");
    }
});

it('labels every movement type and reservation status in English', function (): void {
    foreach (inventoryEnumCases() as $key) {
        expect(__($key, [], 'en'))->not->toBe($key, "missing en label: {$key}");
    }
});

it('keeps the two inventory files the same shape', function (): void {
    $flatten = function (array $strings, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($strings as $key => $value) {
            $keys = is_array($value)
                ? [...$keys, ...$flatten($value, "{$prefix}{$key}.")]
                : [...$keys, "{$prefix}{$key}"];
        }

        return $keys;
    };

    $tr = $flatten(require base_path('lang/tr/inventory.php'));
    $en = $flatten(require base_path('lang/en/inventory.php'));

    sort($tr);
    sort($en);

    /*
     * Turkish is the platform's default and English is the translation, so the
     * drift this catches is real: a string added to one file and forgotten in the
     * other renders as a raw key to whoever runs the panel in the other locale —
     * and only for them, which is exactly the bug nobody reproduces.
     */
    expect($en)->toBe($tr);
});
