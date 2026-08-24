<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\Models\Product;

/**
 * Builds one product's description from what the product already says (ADR-088).
 *
 * **IT INVENTS NOTHING.** Every clause comes from a field the row carries or a
 * token parsed out of its own title. A product whose title does not say "50 ml"
 * gets a sentence that does not mention volume — not a guessed one. That is the
 * whole difference between this and generation, and it is why the output is safe
 * to publish across seven thousand products nobody will read first.
 *
 * **IT CLAIMS NOTHING**, which in Turkey is law rather than taste: a cosmetic or a
 * food supplement that says it treats or prevents a disease is a regulatory
 * offence. The templates state what a product IS and what family it belongs to,
 * and close with the footer that family is required to carry. A test scans every
 * string this class can produce against `forbidden_claims`.
 *
 * **THE BRAND IS OPTIONAL, THE TITLE AND CATEGORY ARE NOT.** Half the catalogue
 * has no brand, and a sentence without one is still true — "Ürün X, Cilt Bakımı
 * kategorisinde yer alan bir üründür" needs no manufacturer to be honest. A
 * product with no title or no category produces null and is skipped, because
 * there is nothing truthful left to say.
 */
final class ProductDescriptionTemplate
{
    /**
     * The generated description, or null when the product cannot be described
     * without inventing something.
     *
     * @param array<int, string> $categoryPath root-first category names
     */
    public function for(Product $product, array $categoryPath, ?string $rootSlug): ?string
    {
        $title = trim($product->localized('title'));
        $category = trim((string) (end($categoryPath) ?: ''));

        if ($title === '' || $category === '') {
            return null;
        }

        $family = $this->familyFor($rootSlug);
        $brand = trim((string) $product->brand?->name);

        $sentences = [];

        /*
        | THE OPENING SENTENCE, and the only one guaranteed to exist. The brand is
        | prefixed only when there is one — an empty prefix would leave a leading
        | space and a sentence starting mid-air.
        */
        $subject = $brand !== '' && ! $this->titleAlreadyNames($title, $brand)
            ? $brand.' '.$title
            : $title;

        $sentences[] = sprintf(
            '%s, %s kategorisinde yer alan bir %s.',
            $subject,
            $category,
            (string) $family['noun'],
        );

        foreach ($this->detailSentences($title) as $detail) {
            $sentences[] = $detail;
        }

        $sentences[] = 'Orijinal ürün, onaylı satıcılar tarafından gönderilir.';

        if ((string) $family['legal'] !== '') {
            $sentences[] = (string) $family['legal'];
        }

        return implode(' ', $sentences);
    }

    /**
     * @return array{noun: string, legal: string}
     */
    private function familyFor(?string $rootSlug): array
    {
        /** @var array<string, array{noun: string, legal: string}> $families */
        $families = (array) config('product_descriptions.families', []);
        /** @var array<string, string> $roots */
        $roots = (array) config('product_descriptions.roots', []);

        $key = $rootSlug !== null && isset($roots[$rootSlug])
            ? $roots[$rootSlug]
            : (string) config('product_descriptions.default_family', 'general');

        return $families[$key] ?? ['noun' => 'üründür', 'legal' => ''];
    }

    /**
     * Detail clauses, in a fixed order so the same product always describes
     * itself the same way. Absent tokens simply produce no sentence.
     *
     * @return array<int, string>
     */
    private function detailSentences(string $title): array
    {
        /** @var array<string, string> $patterns */
        $patterns = (array) config('product_descriptions.tokens', []);

        $sentences = [];

        $isMultipack = isset($patterns['multipack'])
            && preg_match($patterns['multipack'], $title) === 1;

        if (! $isMultipack && isset($patterns['amount']) && preg_match($patterns['amount'], $title, $m) === 1) {
            // "Net miktarı 50 ml." — the number keeps the unit it was written
            // with, because converting one would be asserting something the
            // title did not.
            $sentences[] = sprintf('Net miktarı %s %s.', $m[1], mb_strtolower($m[2]));
        }

        $form = $this->formIn($title);

        if ($form !== null) {
            $sentences[] = mb_convert_case($form, MB_CASE_TITLE, 'UTF-8').' formundadır.';
        }

        if (isset($patterns['spf']) && preg_match($patterns['spf'], $title, $m) === 1) {
            $sentences[] = sprintf('SPF %d koruma faktörüne sahiptir.', (int) $m[1]);
        }

        if (isset($patterns['pack']) && preg_match($patterns['pack'], $title, $m) === 1) {
            // "5'li pakettir." — NOT "Paket içeriği 5'li adettir", which is what
            // this said first and is not Turkish: the -li suffix already means
            // "of N", so "adet" after it is both redundant and ungrammatical.
            $sentences[] = sprintf('%d\'%s pakettir.', (int) $m[1], mb_strtolower($m[2]));
        }

        return $sentences;
    }

    /**
     * The form word a title names, longest first.
     *
     * **TURKISH AGGLUTINATES, SO A WHOLE-WORD MATCH FINDS ALMOST NOTHING.** Titles
     * say "Nemlendirici Kremi", "Duş Jeli", "Saç Şampuanı", "Diş Macunu" — the
     * stem never appears bare. A plain word-boundary pattern matched none of them
     * and this silently produced descriptions with no form sentence at all.
     *
     * So a SHORT, CLOSED suffix set is allowed after the stem and a letter
     * boundary is still required after that. The closed set is the point: letting
     * any letters follow would have `jel` match `jelatin` and `toz` match `tozluk`.
     *
     * Longest stem first, so "duş jeli" cannot be reported as "jel".
     */
    private function formIn(string $title): ?string
    {
        /** @var array<int, string> $forms */
        $forms = (array) config('product_descriptions.forms', []);

        usort($forms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $haystack = mb_strtolower($title, 'UTF-8');

        foreach ($forms as $form) {
            $stem = preg_quote(mb_strtolower($form, 'UTF-8'), '/');

            // Accusative/possessive (-i -ı -u -ü, -si -sı -su -sü) and plural
            // (-ler -lar, -leri -ları). Nothing else.
            $suffix = '(?:[iıuü]|s[iıuü]|l[ae]r[iı]?)?';

            if (preg_match('/(?<![\p{L}])'.$stem.$suffix.'(?![\p{L}])/u', $haystack) === 1) {
                return $form;
            }
        }

        return null;
    }

    /**
     * Whether the title already opens with the brand.
     *
     * Supplier titles usually do ("Bioderma Sensibio H2O"), and prefixing it
     * again would produce "Bioderma Bioderma Sensibio H2O" seven thousand times.
     */
    private function titleAlreadyNames(string $title, string $brand): bool
    {
        return str_starts_with(
            mb_strtolower($title, 'UTF-8'),
            mb_strtolower($brand, 'UTF-8'),
        );
    }
}
