<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Support;

/**
 * Folds Turkish text to plain lowercase ASCII, so that what a shopper types and
 * what the catalogue stores can be compared as the same string.
 *
 * **THE BUG THIS EXISTS FOR.** Search matched with `ILIKE '%…%'` under a
 * docblock claiming that "folds Turkish correctly". It does not: `ILIKE` folds
 * CASE (`istanbul` ↔ `İSTANBUL`) and nothing else, so `gunes` found none of the
 * 343 products whose title says `güneş`. Most people type without diacritics —
 * on a phone keyboard almost everyone does — so the site answered "0 sonuç" to
 * its most common query shape.
 *
 * **Both sides go through here, and that is the whole design.** The needle is
 * folded in PHP; the haystack is folded once when the product is saved and kept
 * in `products.search_text`. Nothing is folded at query time in SQL, which is
 * what kept the old code branching on the driver — Postgres `ILIKE` against
 * SQLite `LIKE`, each lying about Turkish in a different way.
 *
 * **`ı` and `İ` are why `mb_strtolower` alone will not do.** Turkish has two
 * i's, and Unicode lowercasing keeps them distinct: `İ` lowercases to `i̇`
 * (i + combining dot) and `I` to `i`, while `ı` stays `ı`. A shopper typing
 * `urıage` with a dotless ı must still find `uriage`. So the dotted pair and the
 * dotless pair are both mapped to plain `i` BEFORE lowercasing.
 */
final class TurkishFold
{
    /**
     * Turkish letters to their ASCII skeleton, uppercase forms included.
     *
     * The uppercase entries are not redundant: they are replaced before
     * `mb_strtolower` runs, which is what stops `İ` from becoming `i` + U+0307.
     */
    private const MAP = [
        'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g',
        'ı' => 'i', 'I' => 'i',
        'İ' => 'i', 'i' => 'i',
        'ö' => 'o', 'Ö' => 'o',
        'ş' => 's', 'Ş' => 's',
        'ü' => 'u', 'Ü' => 'u',

        /*
        | **THE REST OF LATIN-1, BECAUSE THE SHELVES ARE FRENCH.** A Turkish-only
        | map is right about the language and wrong about this catalogue: Avène,
        | Nuxe, Bioderma, La Roche-Posay and Uriage are what a dermocosmetics
        | shopper searches for, and nobody types the grave accent in "Avène".
        | Without these, `avene` found nothing — the same failure as `gunes`,
        | one aisle over.
        */
        'á' => 'a', 'Á' => 'a', 'à' => 'a', 'À' => 'a',
        'â' => 'a', 'Â' => 'a', 'ä' => 'a', 'Ä' => 'a',
        'ã' => 'a', 'Ã' => 'a', 'å' => 'a', 'Å' => 'a',
        'é' => 'e', 'É' => 'e', 'è' => 'e', 'È' => 'e',
        'ê' => 'e', 'Ê' => 'e', 'ë' => 'e', 'Ë' => 'e',
        'í' => 'i', 'Í' => 'i', 'ì' => 'i', 'Ì' => 'i',
        'î' => 'i', 'Î' => 'i', 'ï' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'Ó' => 'o', 'ò' => 'o', 'Ò' => 'o',
        'ô' => 'o', 'Ô' => 'o', 'õ' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'Ú' => 'u', 'ù' => 'u', 'Ù' => 'u',
        'û' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n',
    ];

    public static function fold(string $text): string
    {
        return mb_strtolower(strtr($text, self::MAP), 'UTF-8');
    }

    /**
     * The folded haystack for a product row: everything a shopper might type
     * that identifies THIS product, in one string.
     *
     * The BRAND is in it because a brand-only search used to work by accident —
     * most titles happen to start with the brand, and the ones that do not were
     * unfindable. The CATEGORY name is not: it made every product in an aisle
     * match the aisle's own name, so a leather jacket answered `tişört`.
     *
     * Descriptions are in it because the public listing already searched them;
     * dropping them here would have quietly narrowed results while claiming to
     * widen them. It makes the column noisy, and relevance ORDER is not
     * something this can fix — that needs a real engine (work order, tier 2).
     *
     * @param array<int, string|null> $parts
     */
    public static function haystack(array $parts): string
    {
        $clean = array_filter(array_map(
            static fn (?string $part): string => trim((string) $part),
            $parts,
        ), static fn (string $part): bool => $part !== '');

        return self::fold(implode(' ', $clean));
    }

    /**
     * The tokens a query must ALL match.
     *
     * Split on whitespace so "leke serum" finds a title that says
     * "Serum … Leke Karşıtı": the old single `%leke serum%` demanded the two
     * words be adjacent and in that order, which is not how anybody searches.
     *
     * @return array<int, string>
     */
    public static function tokens(string $query): array
    {
        $tokens = preg_split('/\s+/u', self::fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }
}
