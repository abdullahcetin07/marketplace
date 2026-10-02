<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A name a courier can hand a parcel to.
 *
 * **FOR A DELIVERY RECIPIENT, NEVER FOR AN ACCOUNT HOLDER.** ADR-012 makes
 * `users.last_name` nullable on purpose — "it collapses cleanly to just the given
 * name for sole traders and for cultures with a single name" — and that decision
 * stands. This rule is about a different thing: a cargo label, where a courier
 * has to identify one person at one door and every Turkish carrier's form asks
 * for ad AND soyad.
 *
 * **THE FORM WAS ASKING THE WRONG QUESTION.** The field was labelled "Alıcı adı"
 * — *recipient's name* — with `required|string|max:255` behind it, so a shopper
 * who typed "Taha" had answered correctly. Six of twenty-six paid orders carried
 * a single word, two of them waiting to be handed to a carrier when this was
 * found (2026-10-02). The label now asks for ad AND soyad; this is what holds it.
 *
 * **TWO PARTS, EACH REAL.** An initial is not a surname a courier can use, so
 * "Ahmet Y." does not pass — both of the first two parts must be at least two
 * characters. Anything beyond two is left alone: middle names, compound
 * surnames and "oğlu" suffixes are none of this rule's business.
 */
final class FullName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail(__('validation.full_name'))->translate();
        }
    }

    public static function isValid(string $value): bool
    {
        $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            return false;
        }

        // LETTERS, not characters: "Y." is two characters and one letter, and a
        // courier cannot use an initial as a surname. Unicode-aware, because "Ş"
        // is one letter and two bytes.
        return self::letters($parts[0]) >= 2 && self::letters($parts[1]) >= 2;
    }

    private static function letters(string $part): int
    {
        return mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $part));
    }
}
