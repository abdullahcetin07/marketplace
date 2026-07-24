<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Where a currency symbol sits relative to the amount.
 *
 * Turkish convention places it after ("1.499,00 ₺"); most Western currencies
 * place it before ("$19.99"). Stored per currency because it genuinely varies,
 * and an enum because there are exactly two possibilities.
 */
enum SymbolPosition: string
{
    use HasEnumHelpers;

    case Before = 'before';
    case After = 'after';

    /**
     * Assemble a formatted amount with its symbol.
     *
     * The space before a trailing symbol is conventional in Turkish and most
     * European formats; a leading symbol is set tight against the digits.
     */
    public function apply(string $formattedAmount, string $symbol): string
    {
        return $this === self::Before
            ? $symbol.$formattedAmount
            : $formattedAmount.' '.$symbol;
    }
}
