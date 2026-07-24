<?php

declare(strict_types=1);

namespace App\Modules\Localization\Domain\Enums;

use App\Shared\Enums\Concerns\HasEnumHelpers;

/**
 * Writing direction of a language.
 *
 * An enum, not a column of free text: `dir="ltr"` and `dir="rtl"` are the only
 * two values HTML accepts, and layout code branches on them exhaustively.
 * Languages themselves are a lookup table; their direction is not.
 */
enum TextDirection: string
{
    use HasEnumHelpers;

    case Ltr = 'ltr';
    case Rtl = 'rtl';

    public function isRtl(): bool
    {
        return $this === self::Rtl;
    }

    /**
     * Logical start edge — what `text-align` should use for this direction.
     */
    public function start(): string
    {
        return $this === self::Rtl ? 'right' : 'left';
    }

    public function end(): string
    {
        return $this === self::Rtl ? 'left' : 'right';
    }
}
