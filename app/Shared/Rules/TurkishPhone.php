<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number a courier can actually ring.
 *
 * **THE ONLY RULE ON A DELIVERY ADDRESS USED TO BE `max:32`** — so
 * `534-320-588`, nine digits with the last one missing, was accepted, stored and
 * frozen onto an order. Two of 144 production addresses were exactly that, and
 * one of them was on a live 972 TL order waiting to be handed to a carrier
 * (2026-09-24). A parcel with an unreachable number comes back.
 *
 * **THE FORM MASK IS WHY NOBODY NOTICED.** The storefront formats as `AAA-BBB-CCCC`
 * as you type, and nine digits render as `534-320-588` — dashes in the right
 * places, the shape of a finished number. The customer proof-read something that
 * looked complete.
 *
 * **NORMALISE, THEN COUNT.** People write `0532...`, `+90 532 ...`, `90532...`
 * and `532 123 45 67`; all four are the same number and none is a mistake worth
 * refusing. The country code and a single leading zero are stripped, everything
 * that is not a digit is dropped, and what must remain is ten digits.
 *
 * **MOBILE AND LANDLINE BOTH PASS.** A courier wants a mobile and most people
 * give one, but a landline is a real number a real person answers, and refusing
 * it would reject valid input to enforce a preference. Turkish geographic codes
 * start 2, 3 or 4 and mobiles start 5; a leading 1, 6, 7, 8 or 9 is not a
 * subscriber number at all.
 */
final class TurkishPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('validation.turkish_phone'))->translate();

            return;
        }

        if (! self::isValid($value)) {
            $fail(__('validation.turkish_phone'))->translate();
        }
    }

    /**
     * Whether this reaches somebody, independent of how it was typed.
     */
    public static function isValid(string $value): bool
    {
        return self::normalise($value) !== null;
    }

    /**
     * The ten subscriber digits, or null when there is no number here.
     *
     * Exposed because the same question gets asked outside validation — a feed,
     * a repair script, a report — and two answers to "is this a phone number"
     * is how the looser one ends up in the database.
     */
    public static function normalise(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        // `+90`, `0090` and a bare `90` prefix all mean the same country.
        if (str_starts_with($digits, '90') && mb_strlen($digits) > 10) {
            $digits = mb_substr($digits, 2);
        }

        // A single trunk zero, as written on every Turkish business card.
        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        if (preg_match('/^[2-5]\d{9}$/', $digits) !== 1) {
            return null;
        }

        return $digits;
    }
}
